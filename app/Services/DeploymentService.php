<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DeploymentService
{
    private ?Deployment $activeDeployment = null;
    private int $lastOutputCount = 0;
    private float $lastStreamAt = 0.0;
    private int $processCounter = 0;

    public function hasComposer(Project $project): bool
    {
        try {
            $repoPath = $this->resolveRepoPath($project);

            return is_file($repoPath.DIRECTORY_SEPARATOR.'composer.json');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function hasNpm(Project $project): bool
    {
        try {
            $repoPath = $this->resolveRepoPath($project);

            return is_file($repoPath.DIRECTORY_SEPARATOR.'package.json');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function checkForUpdates(Project $project): bool
    {
        $repoPath = $this->resolveRepoPath($project);

        $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune']);
        $head = $this->tryRevParse($repoPath);
        $remote = trim($this->runProcess([
            'git',
            '-C',
            $repoPath,
            'rev-parse',
            'origin/'.$project->default_branch,
        ])->getOutput());

        $hasUpdates = $head !== $remote;
        $project->last_checked_at = now();
        $project->updates_checked_at = now();
        $project->updates_available = $hasUpdates;
        $project->save();

        return $hasUpdates;
    }

    public function releaseStaleRunningDeployments(?int $graceSeconds = null): void
    {
        $grace = $graceSeconds ?? (int) config('gitmanager.deployments.stale_seconds', 3600);
        if ($grace <= 0) {
            return;
        }

        $cutoff = now()->subSeconds($grace);
        $stale = Deployment::query()
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $deployment) {
            $message = 'Marked failed after exceeding '.$grace.' seconds without completion.';
            $deployment->status = 'failed';
            $deployment->finished_at = now();
            $deployment->output_log = trim(($deployment->output_log ? $deployment->output_log."\n" : '').$message);
            $deployment->save();

            if ($deployment->project_id) {
                Project::query()
                    ->where('id', $deployment->project_id)
                    ->update([
                        'last_error_message' => $message,
                        'last_checked_at' => now(),
                    ]);
            }
        }
    }

    public function deploy(Project $project, ?User $user = null, bool $allowDirty = false, bool $ignorePermissionsLock = false): Deployment
    {
        if ($project->permissions_locked && ! $ignorePermissionsLock && ! $project->ssh_enabled) {
            $message = 'Permissions need fixing before deployments can run.';
            if ($project->permissions_issue_message) {
                $message .= ' '.$project->permissions_issue_message;
            }

            $deployment = Deployment::create([
                'project_id' => $project->id,
                'triggered_by' => $user?->id,
                'action' => 'deploy',
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'output_log' => $message,
            ]);

            $project->last_error_message = $message;
            $project->last_checked_at = now();
            $project->save();

            return $deployment;
        }

        if ($project->ssh_enabled) {
            return $this->deployOverSsh($project, $user);
        }

        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'deploy',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $permissionsStep = false;
        $forceStaged = false;
        $previousHealthOk = $project->health_status === 'ok';
        $autoRollbackAttempted = false;

        while (true) {
            $fromHash = null;
            $toHash = null;
            $stashed = false;

            try {
                if (! $allowDirty) {
                    $stashed = $this->stashIfDirty($repoPath, $output);
                }

                $fromHash = $this->tryRevParse($repoPath);
                $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);
            $remoteHash = trim($this->runProcess([
                'git',
                '-C',
                $repoPath,
                'rev-parse',
                'origin/'.$project->default_branch,
            ], $output)->getOutput());

            if ($fromHash && $fromHash === $remoteHash) {
                    $deployment->status = 'success';
                    $deployment->from_hash = $fromHash;
                    $deployment->to_hash = $fromHash;
                    $this->appendWorkflowOutput($deployment, $project, $output);
                    $deployment->output_log = implode("\n", $output);
                    $deployment->finished_at = now();
                    $deployment->save();

                    $project->last_checked_at = now();
                    $project->save();

                $this->endDeploymentStream();
                return $deployment;
            }

            $this->runStagingChecks($project, $repoPath, $remoteHash, $output);

            $this->resetToRemote($project, $repoPath, $project->default_branch, $output, $allowDirty);

                $toHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

                $ftpPlan = $this->planFtpOnlyDependencySync($project, $executionPath, $output);

                if (! $permissionsStep && ! $forceStaged && $this->needsPermissionFix($project, $executionPath)) {
                    $output[] = 'Permission issues detected. Running Fix Permissions.';
                    $this->attemptFixPermissions($project, $executionPath, $output, false);
                    $permissionsStep = true;
                    $forceStaged = true;
                    $output[] = 'Continuing with staged installs after permissions step.';
                }

                $this->runWithSingleRetry(function () use ($project, $executionPath, &$output, &$forceStaged, $ftpPlan): void {
                    if ($project->run_composer_install) {
                        if ($ftpPlan['skipComposerInstall'] ?? false) {
                            $output[] = 'FTP-only pipeline: skipping composer install (manifests unchanged).';
                        } else {
                        $this->runComposerCommandWithFallback(
                            $executionPath,
                            $output,
                            'Composer install',
                            ['composer', 'install', '--no-dev', '--optimize-autoloader'],
                            $forceStaged
                        );
                        }
                    }

                    if ($project->run_npm_install) {
                        if ($ftpPlan['skipNpmInstall'] ?? false) {
                            $output[] = 'FTP-only pipeline: skipping npm install (manifests unchanged).';
                        } else {
                        $this->runNpmInstallWithFallback(
                            $executionPath,
                            $output,
                            'Npm install',
                            $this->npmInstallCommand($executionPath),
                            $forceStaged
                        );
                        }
                    }

                    if ($project->run_build_command && $project->build_command) {
                        $this->ensureBuildOutputWritable($executionPath, $output);
                        $this->logStep($output, 'Build command', $executionPath, $project->build_command);
                        $this->runProjectShellCommand($project->build_command, $output, $executionPath);
                    }

                    if ($project->run_test_command && $project->test_command) {
                        $this->logStep($output, 'Test command', $executionPath, $project->test_command);
                        $this->runTestCommand($project, $project->test_command, $executionPath, $output);
                    }

                    $this->maybeRunLaravelClearCache($project, $output);
                }, $output, 'Post-deploy tasks', function (\Throwable $exception) use (&$output): bool {
                    return ! $this->isPermissionError($exception, $output);
                });

                $this->maybeSyncFtp($project, $executionPath, $output, $ftpPlan['excludePaths'] ?? []);
                $this->maybeRunSshCommands($project, $output);

                if ($stashed) {
                    $this->restoreStashOrReset($repoPath, $output, $toHash ?? 'HEAD');
                }

                $deployment->status = 'success';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = implode("\n", $output);
                $deployment->finished_at = now();
                $deployment->save();

                $project->last_deployed_at = now();
                $project->last_deployed_hash = $toHash;
                $project->last_error_message = null;
                $project->updates_available = false;
                $project->updates_checked_at = now();
                $project->last_checked_at = now();
                $project->save();

                $this->endDeploymentStream();
                return $deployment;
            } catch (\Throwable $exception) {
                if ($fromHash) {
                    $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
                }

                if ($stashed) {
                    $this->restoreStashOrReset($repoPath, $output, $fromHash ?? 'HEAD');
                }

                if (! $permissionsStep && $this->isPermissionError($exception, $output)) {
                    $output[] = 'Permission error detected. Running Fix Permissions.';
                    $this->attemptFixPermissions($project, $executionPath, $output, false);
                    $permissionsStep = true;
                    $forceStaged = true;
                    $output[] = 'Retrying deployment using staged installs.';
                    continue;
                }

                if ($previousHealthOk && ! $autoRollbackAttempted) {
                    $autoRollbackAttempted = true;
                    $output[] = 'Deployment failed. Checking health status for rollback.';
                    $currentHealth = $this->checkHealth($project);
                    if ($currentHealth !== 'ok') {
                        $rollbackTarget = $project->last_deployed_hash ?: $fromHash;
                        if ($rollbackTarget) {
                            $output[] = 'Health check failed after deploy. Attempting rollback.';
                            try {
                                $rollbackDeployment = $this->rollback($project, $user, $rollbackTarget);
                                $output[] = $rollbackDeployment->status === 'success'
                                    ? 'Auto-rollback completed successfully.'
                                    : 'Auto-rollback failed. See rollback log for details.';
                            } catch (\Throwable $rollbackException) {
                                $output[] = 'Auto-rollback failed: '.$rollbackException->getMessage();
                            }
                        } else {
                            $output[] = 'Auto-rollback skipped: no previous deployment available.';
                        }
                    } else {
                        $output[] = 'Health check still passing; no rollback required.';
                    }
                }

                $deployment->status = 'failed';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $output[] = $exception->getMessage();
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = trim(implode("\n", $output));
                $deployment->finished_at = now();
                $deployment->save();

                $this->endDeploymentStream();
                $project->last_error_message = $exception->getMessage();
                $project->last_checked_at = now();
                $project->save();
                return $deployment;
            }
        }

        return $deployment;
    }

    public function rollback(Project $project, ?User $user = null, ?string $targetHash = null): Deployment
    {
        if ($project->permissions_locked && ! $project->ssh_enabled) {
            $message = 'Permissions need fixing before rollbacks can run.';
            if ($project->permissions_issue_message) {
                $message .= ' '.$project->permissions_issue_message;
            }

            $deployment = Deployment::create([
                'project_id' => $project->id,
                'triggered_by' => $user?->id,
                'action' => 'rollback',
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'output_log' => $message,
            ]);

            $project->last_error_message = $message;
            $project->last_checked_at = now();
            $project->save();

            return $deployment;
        }

        if ($project->ssh_enabled) {
            return $this->rollbackOverSsh($project, $user, $targetHash);
        }

        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'rollback',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $attempts = 0;

        while ($attempts < 2) {
            $attempts++;
            $fromHash = null;
            $toHash = $targetHash;
            $stashed = false;

            try {
                $stashed = $this->stashIfDirty($repoPath, $output);
                $fromHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

                if (! $toHash) {
                    $previous = $project->deployments()
                        ->where('action', 'deploy')
                        ->where('status', 'success')
                        ->whereNotNull('to_hash')
                        ->where('to_hash', '!=', $fromHash)
                        ->orderByDesc('started_at')
                        ->first();

                    if (! $previous) {
                        throw new \RuntimeException('No previous successful deployment found to rollback to.');
                    }

                    $toHash = $previous->to_hash;
                }

                $preservePath = $this->snapshotPreservePaths($project, $repoPath, $output);
                $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $toHash], $output);
                $this->restorePreservedPaths($repoPath, $preservePath, $output);

                $ftpPlan = $this->planFtpOnlyDependencySync($project, $executionPath, $output);

                $this->runWithSingleRetry(function () use ($project, $executionPath, &$output, $ftpPlan): void {
                    if ($project->run_composer_install) {
                        if ($ftpPlan['skipComposerInstall'] ?? false) {
                            $output[] = 'FTP-only pipeline: skipping composer install (manifests unchanged).';
                        } else {
                        $this->runComposerCommandWithFallback(
                            $executionPath,
                            $output,
                            'Composer install',
                            ['composer', 'install', '--no-dev', '--optimize-autoloader']
                        );
                        }
                    }

                    if ($project->run_npm_install) {
                        if ($ftpPlan['skipNpmInstall'] ?? false) {
                            $output[] = 'FTP-only pipeline: skipping npm install (manifests unchanged).';
                        } else {
                        $this->runNpmInstallWithFallback(
                            $executionPath,
                            $output,
                            'Npm install',
                            $this->npmInstallCommand($executionPath)
                        );
                        }
                    }

                    if ($project->run_build_command && $project->build_command) {
                        $this->ensureBuildOutputWritable($executionPath, $output);
                        $this->logStep($output, 'Build command', $executionPath, $project->build_command);
                        $this->runProjectShellCommand($project->build_command, $output, $executionPath);
                    }

                    $this->maybeRunLaravelClearCache($project, $output);
                }, $output, 'Post-rollback tasks');

                $this->maybeSyncFtp($project, $executionPath, $output, $ftpPlan['excludePaths'] ?? []);
                $this->maybeRunSshCommands($project, $output);

                if ($stashed) {
                    $this->restoreStashOrReset($repoPath, $output, $toHash ?? 'HEAD');
                }

                $deployment->status = 'success';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = implode("\n", $output);
                $deployment->finished_at = now();
                $deployment->save();

                $project->last_deployed_at = now();
                $project->last_deployed_hash = $toHash;
                $project->last_error_message = null;
                $project->save();

                $this->endDeploymentStream();
                return $deployment;
            } catch (\Throwable $exception) {
                if ($fromHash) {
                    $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
                }

                if ($stashed) {
                    $this->restoreStashOrReset($repoPath, $output, $fromHash ?? 'HEAD');
                }

                if ($attempts < 2) {
                    $output[] = 'Rollback failed. Retrying once.';
                    continue;
                }

                $deployment->status = 'failed';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
                $deployment->finished_at = now();
                $deployment->save();

                $this->endDeploymentStream();
                $project->last_error_message = $exception->getMessage();
                $project->save();
            }
        }

        return $deployment;
    }

    private function deployOverSsh(Project $project, ?User $user = null): Deployment
    {
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'deploy',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $fromHash = null;
        $toHash = null;
        $previousHealthOk = $project->health_status === 'ok';
        $autoRollbackAttempted = false;

        try {
            $connection = $this->resolveSshConnection($project);
            $rootLabel = $connection['root'] ? ' ('.$connection['root'].')' : '';
            $output[] = 'Starting SSH deployment to '.$connection['host'].$rootLabel.'.';
            $this->maybeStreamOutput($output, true);

            $this->ensureRemoteRepo($project, $connection, $output);
            $fromHash = $this->sshTryRevParse($connection, 'HEAD', $output);
            $this->runSshCommand($connection, 'GIT_TERMINAL_PROMPT=0 git fetch --all --prune', $output);

            $remoteRef = 'origin/'.($project->default_branch ?: 'main');
            $remoteHash = $this->sshRevParse($connection, $remoteRef, $output);

            if ($fromHash && $remoteHash && $fromHash === $remoteHash) {
                $deployment->status = 'success';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $fromHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = implode("\n", $output);
                $deployment->finished_at = now();
                $deployment->save();

                $project->last_checked_at = now();
                $project->updates_checked_at = now();
                $project->save();

                $this->endDeploymentStream();
                return $deployment;
            }

            $this->runSshCommand($connection, 'git reset --hard '.escapeshellarg($remoteRef), $output);
            $this->runSshCommand($connection, $this->remoteGitCleanCommand($project, false), $output);
            $toHash = $this->sshRevParse($connection, 'HEAD', $output);

            $this->runWithSingleRetry(function () use ($project, $connection, &$output): void {
                if ($project->run_composer_install) {
                    $this->runSshCommand(
                        $connection,
                        'composer install --no-dev --optimize-autoloader',
                        $output
                    );
                }

                if ($project->run_npm_install) {
                    $this->runSshCommand(
                        $connection,
                        $this->remoteNpmInstallCommand(),
                        $output
                    );
                }

                if ($project->run_build_command && $project->build_command) {
                    $this->runSshCommand($connection, $project->build_command, $output);
                }

                if ($project->run_test_command && $project->test_command) {
                    $this->runSshCommand($connection, $project->test_command, $output);
                }

                $this->maybeRunLaravelClearCacheOverSsh($project, $connection, $output);
            }, $output, 'SSH post-deploy tasks');

            if ($project->ftp_enabled) {
                $output[] = 'FTPS sync skipped: SSH deployment is enabled for this project.';
            }

            $this->maybeRunSshCommands($project, $output);

            $deployment->status = 'success';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $this->appendWorkflowOutput($deployment, $project, $output);
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_deployed_at = now();
            $project->last_deployed_hash = $toHash;
            $project->last_error_message = null;
            $project->updates_available = false;
            $project->updates_checked_at = now();
            $project->last_checked_at = now();
            $project->save();

            $this->endDeploymentStream();
            return $deployment;
        } catch (\Throwable $exception) {
            if ($previousHealthOk && ! $autoRollbackAttempted) {
                $autoRollbackAttempted = true;
                $output[] = 'Deployment failed. Checking health status for rollback.';
                $currentHealth = $this->checkHealth($project);
                if ($currentHealth !== 'ok') {
                    $rollbackTarget = $project->last_deployed_hash ?: $fromHash;
                    if ($rollbackTarget) {
                        $output[] = 'Health check failed after deploy. Attempting rollback.';
                        try {
                            $rollbackDeployment = $this->rollback($project, $user, $rollbackTarget);
                            $output[] = $rollbackDeployment->status === 'success'
                                ? 'Auto-rollback completed successfully.'
                                : 'Auto-rollback failed. See rollback log for details.';
                        } catch (\Throwable $rollbackException) {
                            $output[] = 'Auto-rollback failed: '.$rollbackException->getMessage();
                        }
                    } else {
                        $output[] = 'Auto-rollback skipped: no previous deployment available.';
                    }
                } else {
                    $output[] = 'Health check still passing; no rollback required.';
                }
            }

            $deployment->status = 'failed';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $output[] = $exception->getMessage();
            $this->appendWorkflowOutput($deployment, $project, $output);
            $deployment->output_log = trim(implode("\n", $output));
            $deployment->finished_at = now();
            $deployment->save();

            $this->endDeploymentStream();
            $project->last_error_message = $exception->getMessage();
            $project->last_checked_at = now();
            $project->save();
            return $deployment;
        }
    }

    private function rollbackOverSsh(Project $project, ?User $user = null, ?string $targetHash = null): Deployment
    {
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'rollback',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $attempts = 0;

        while ($attempts < 2) {
            $attempts++;
            $fromHash = null;
            $toHash = $targetHash;

            try {
                $connection = $this->resolveSshConnection($project);
                $rootLabel = $connection['root'] ? ' ('.$connection['root'].')' : '';
                $output[] = 'Starting SSH rollback on '.$connection['host'].$rootLabel.'.';
                $this->maybeStreamOutput($output, true);

                $fromHash = $this->sshTryRevParse($connection, 'HEAD', $output);

                if (! $toHash) {
                    $previous = $project->deployments()
                        ->where('action', 'deploy')
                        ->where('status', 'success')
                        ->whereNotNull('to_hash')
                        ->when($fromHash, fn ($query) => $query->where('to_hash', '!=', $fromHash))
                        ->orderByDesc('started_at')
                        ->first();

                    if (! $previous) {
                        throw new \RuntimeException('No previous successful deployment found to rollback to.');
                    }

                    $toHash = $previous->to_hash;
                }

                $this->runSshCommand($connection, 'GIT_TERMINAL_PROMPT=0 git fetch --all --prune', $output);
                $this->runSshCommand($connection, 'git reset --hard '.escapeshellarg($toHash), $output);
                $this->runSshCommand($connection, $this->remoteGitCleanCommand($project, false), $output);

                $this->runWithSingleRetry(function () use ($project, $connection, &$output): void {
                    if ($project->run_composer_install) {
                        $this->runSshCommand(
                            $connection,
                            'composer install --no-dev --optimize-autoloader',
                            $output
                        );
                    }

                    if ($project->run_npm_install) {
                        $this->runSshCommand(
                            $connection,
                            $this->remoteNpmInstallCommand(),
                            $output
                        );
                    }

                    if ($project->run_build_command && $project->build_command) {
                        $this->runSshCommand($connection, $project->build_command, $output);
                    }

                    $this->maybeRunLaravelClearCacheOverSsh($project, $connection, $output);
                }, $output, 'SSH post-rollback tasks');

                if ($project->ftp_enabled) {
                    $output[] = 'FTPS sync skipped: SSH deployment is enabled for this project.';
                }

                $this->maybeRunSshCommands($project, $output);

                $deployment->status = 'success';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = implode("\n", $output);
                $deployment->finished_at = now();
                $deployment->save();

                $project->last_deployed_at = now();
                $project->last_deployed_hash = $toHash;
                $project->last_error_message = null;
                $project->save();

                $this->endDeploymentStream();
                return $deployment;
            } catch (\Throwable $exception) {
                if ($attempts < 2) {
                    $output[] = 'Rollback failed. Retrying once.';
                    continue;
                }

                $deployment->status = 'failed';
                $deployment->from_hash = $fromHash ?? null;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
                $deployment->finished_at = now();
                $deployment->save();

                $this->endDeploymentStream();
                $project->last_error_message = $exception->getMessage();
                $project->save();
            }
        }

        return $deployment;
    }

    public function updateDependencies(Project $project, ?User $user = null): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'dependency_update',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $attempts = 0;

        while ($attempts < 2) {
            $attempts++;
            $fromHash = null;
            $toHash = null;

            try {
                $this->ensureCleanWorkingTree($repoPath, $output, true);
                $fromHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

                if (! $project->allow_dependency_updates) {
                    throw new \RuntimeException('Dependency updates are disabled for this project.');
                }

                $this->runWithSingleRetry(function () use ($project, $executionPath, &$output): void {
                    if ($project->run_composer_install) {
                        $this->runComposerCommandWithFallback(
                            $executionPath,
                            $output,
                            'Composer update',
                            ['composer', 'update']
                        );
                    }

                    if ($project->run_npm_install) {
                        $this->runNpmCommandWithFallback(
                            $executionPath,
                            $output,
                            'Npm update',
                            ['npm', 'update'],
                            true
                        );
                    }

                    if ($project->run_build_command && $project->build_command) {
                        $this->ensureBuildOutputWritable($executionPath, $output);
                        $this->logStep($output, 'Build command', $executionPath, $project->build_command);
                        $this->runProjectShellCommand($project->build_command, $output, $executionPath);
                    }

                    if ($project->run_test_command && $project->test_command) {
                        $this->logStep($output, 'Test command', $executionPath, $project->test_command);
                        $this->runTestCommand($project, $project->test_command, $executionPath, $output);
                    }

                    $this->maybeRunLaravelClearCache($project, $output);
                }, $output, 'Dependency update tasks');

                $toHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

                $deployment->status = 'success';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = implode("\n", $output);
                $deployment->finished_at = now();
                $deployment->save();

                $this->endDeploymentStream();
                return $deployment;
            } catch (\Throwable $exception) {
                if ($fromHash) {
                    $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
                }

                if ($attempts < 2) {
                    $output[] = 'Dependency update failed. Retrying once.';
                    continue;
                }

                $deployment->status = 'failed';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $toHash;
                $this->appendWorkflowOutput($deployment, $project, $output);
                $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
                $deployment->finished_at = now();
                $deployment->save();
                $this->endDeploymentStream();
            }
        }

        return $deployment;
    }

    public function composerInstall(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'composer_install', function (string $path, array &$output): void {
            $this->runComposerCommandWithFallback(
                $path,
                $output,
                'Composer install',
                ['composer', 'install', '--no-dev', '--optimize-autoloader']
            );
        }, true);
    }

    public function composerUpdate(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'composer_update', function (string $path, array &$output): void {
            $this->runComposerCommandWithFallback(
                $path,
                $output,
                'Composer update',
                ['composer', 'update']
            );
        }, true);
    }

    public function composerAudit(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'composer_audit', function (string $path, array &$output): void {
            $this->runProjectProcess(['composer', 'audit'], $output, $path);
        }, true);
    }

    public function appClearCache(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'app_clear_cache', function (string $path, array &$output) use ($project): void {
            $laravelRoot = $this->findLaravelRoot($project->local_path)
                ?? $this->findLaravelRoot($path);

            if (! $laravelRoot) {
                throw new \RuntimeException('Laravel app not found for this project.');
            }

            if (! $this->artisanCommandExists($laravelRoot, 'app:clear-cache', $output)) {
                throw new \RuntimeException('Command app:clear-cache not found.');
            }

            $this->runProjectProcess(['php', 'artisan', 'app:clear-cache'], $output, $laravelRoot);
        });
    }

    public function npmInstall(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'npm_install', function (string $path, array &$output): void {
            $this->runNpmInstallWithFallback(
                $path,
                $output,
                'Npm install',
                ['npm', 'install']
            );
        });
    }

    public function npmUpdate(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'npm_update', function (string $path, array &$output): void {
            $this->runNpmCommandWithFallback(
                $path,
                $output,
                'Npm update',
                ['npm', 'update'],
                true
            );
        });
    }

    public function npmAuditFix(Project $project, ?User $user = null, bool $force = false): Deployment
    {
        return $this->runMaintenanceAction($project, $user, $force ? 'npm_audit_fix_force' : 'npm_audit_fix', function (string $path, array &$output) use ($force): void {
            $command = ['npm', 'audit', 'fix'];
            if ($force) {
                $command[] = '--force';
            }
            $this->runNpmCommandWithFallback(
                $path,
                $output,
                'Npm audit fix',
                $command,
                true
            );
        });
    }

    public function fixPermissions(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'fix_permissions', function (string $path, array &$output) use ($project): void {
            $this->attemptFixPermissions($project, $path, $output, true);
        });
    }

    public function runCustomCommand(Project $project, ?User $user = null, string $command = ''): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'custom_command', function (string $path, array &$output) use ($command): void {
            $command = trim($command);
            if ($command === '') {
                throw new \RuntimeException('Command cannot be empty.');
            }

            $output[] = '$ '.$command;
            $this->runProjectShellCommand($command, $output, $path);
        });
    }

    public function previewBuild(Project $project, ?User $user = null, string $commit = ''): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'preview_build',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $attempts = 0;

        try {
            while ($attempts < 2) {
                $attempts++;

                try {
                    $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);

                    $target = trim($commit) !== '' ? trim($commit) : 'origin/'.$project->default_branch;
                    $hash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', $target], $output)->getOutput());
                    $short = substr($hash, 0, 7);

                    $slug = Str::slug($project->name) ?: 'project';
                    $basePath = rtrim((string) config('gitmanager.preview.path', storage_path('app/previews')), DIRECTORY_SEPARATOR);
                    $previewPath = $basePath.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$short;

                    $this->ensurePath(dirname($previewPath));

                    if (is_dir($previewPath)) {
                        $this->runProcess(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $previewPath], $output, null, false);
                        $this->deleteDirectory($previewPath);
                    }

                    $this->runProcess(['git', '-C', $repoPath, 'worktree', 'add', '--force', $previewPath, $hash], $output);
                    $output[] = 'Preview path: '.$previewPath;

                    $baseUrl = trim((string) config('gitmanager.preview.base_url', ''));
                    if ($baseUrl !== '') {
                        $output[] = 'Preview url: '.rtrim($baseUrl, '/').'/'.$slug.'/'.$short;
                    }

                    $this->runWithSingleRetry(function () use ($project, $previewPath, &$output): void {
                        if ($project->run_composer_install && is_file($previewPath.DIRECTORY_SEPARATOR.'composer.json')) {
                            $this->runComposerCommandWithFallback(
                                $previewPath,
                                $output,
                                'Composer install',
                                ['composer', 'install', '--no-dev', '--optimize-autoloader']
                            );
                        }

                        if ($project->run_npm_install && is_file($previewPath.DIRECTORY_SEPARATOR.'package.json')) {
                            $this->runNpmInstallWithFallback(
                                $previewPath,
                                $output,
                                'Npm install',
                                $this->npmInstallCommand($previewPath)
                            );
                        }

                        if ($project->run_build_command && $project->build_command) {
                            $this->ensureBuildOutputWritable($previewPath, $output);
                            $this->runProjectShellCommand($project->build_command, $output, $previewPath);
                        }

                        if ($project->run_test_command && $project->test_command) {
                            $this->runTestCommand($project, $project->test_command, $previewPath, $output);
                        }
                    }, $output, 'Preview build tasks');

                    $deployment->status = 'success';
                    $this->appendWorkflowOutput($deployment, $project, $output);
                    $deployment->output_log = implode("\n", $output);
                    $deployment->finished_at = now();
                    $deployment->save();

                    return $deployment;
                } catch (\Throwable $exception) {
                    if ($attempts < 2) {
                        $output[] = 'Preview build failed. Retrying once.';
                        continue;
                    }

                    $deployment->status = 'failed';
                    $this->appendWorkflowOutput($deployment, $project, $output);
                    $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
                    $deployment->finished_at = now();
                    $deployment->save();
                }
            }

            return $deployment;
        } finally {
            $this->endDeploymentStream();
        }
    }

    public function checkHealth(Project $project): string
    {
        $healthUrl = $this->resolveHealthUrl($project);

        if (! $healthUrl) {
            $project->health_status = 'na';
            $project->health_checked_at = now();
            $project->save();

            return 'na';
        }

        try {
            $response = Http::timeout(10)->get($healthUrl);
            $status = $response->successful() ? 'ok' : 'na';
        } catch (\Throwable $exception) {
            $status = 'na';
        }

        $project->health_status = $status;
        $project->health_checked_at = now();
        $project->save();

        return $status;
    }

    /**
     * @return array{status: string, message: string, parent?: string}
     */
    public function checkPathPermissions(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [
                'status' => 'missing',
                'message' => 'Enter a local path to run the permission check.',
            ];
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return [
                'status' => 'missing',
                'message' => 'Enter a valid local path to run the permission check.',
            ];
        }

        if (is_dir($path)) {
            if (is_writable($path)) {
                return [
                    'status' => 'ok',
                    'message' => 'Directory exists and is writable.',
                ];
            }

            return $this->permissionStatusForParent($path, true);
        }

        $parent = $this->closestExistingParent($path);
        if (! $parent) {
            return [
                'status' => 'missing_parent',
                'message' => 'No existing parent directory found for this path.',
            ];
        }

        if (is_writable($parent)) {
            return [
                'status' => 'can_create',
                'message' => 'Parent directory is writable. The path can be created by the web user.',
                'parent' => $parent,
            ];
        }

        return $this->permissionStatusForParent($parent, false);
    }

    private function ensurePath(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
            if ($this->attemptPrivilegedPathCreate($path)) {
                return;
            }
            throw new \RuntimeException('Project path not found or not writable: '.$path);
        }
    }

    private function attemptPrivilegedPathCreate(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $parent = $this->closestExistingParent($path);
        if (! $parent) {
            return false;
        }

        $owner = @fileowner($parent);
        $group = @filegroup($parent);
        if ($owner === false || $group === false) {
            return false;
        }

        if ($this->runningAsRoot()) {
            if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                return false;
            }

            if (! $this->applyOwnership($path, (int) $owner, (int) $group)) {
                return false;
            }

            return true;
        }

        if (! $this->sudoEnabled()) {
            return false;
        }

        $sudo = $this->sudoBinary();
        if ($sudo === '') {
            return false;
        }

        if (! $this->runSilentProcess([$sudo, '-n', 'mkdir', '-p', $path])) {
            return false;
        }

        $chownTarget = $owner.':'.$group;
        if (! $this->runSilentProcess([$sudo, '-n', 'chown', '-R', $chownTarget, $path])) {
            return false;
        }

        return $this->verifyOwnership($path, (int) $owner, (int) $group);
    }

    private function closestExistingParent(string $path): ?string
    {
        $cursor = rtrim($path, DIRECTORY_SEPARATOR);
        if ($cursor === '') {
            return null;
        }

        while (! is_dir($cursor)) {
            $parent = dirname($cursor);
            if ($parent === '' || $parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        return is_dir($cursor) ? $cursor : null;
    }

    private function runningAsRoot(): bool
    {
        if (! function_exists('posix_geteuid')) {
            return false;
        }

        return posix_geteuid() === 0;
    }

    private function sudoEnabled(): bool
    {
        return (bool) config('gitmanager.sudo.enabled', false);
    }

    private function sudoBinary(): string
    {
        $binary = trim((string) config('gitmanager.sudo.binary', 'sudo'));
        $binary = trim($binary, "\"' ");
        return $binary;
    }

    private function runSilentProcess(array $command): bool
    {
        try {
            $process = new Process($command);
            $process->setTimeout(60);
            $process->run();
            return $process->isSuccessful();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function applyOwnership(string $path, int $owner, int $group): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        if (! $this->runningAsRoot()) {
            return false;
        }

        $this->chownRecursivePath($path, $owner, $group);
        return $this->verifyOwnership($path, $owner, $group);
    }

    private function verifyOwnership(string $path, int $owner, int $group): bool
    {
        $currentOwner = @fileowner($path);
        $currentGroup = @filegroup($path);
        if ($currentOwner === false || $currentGroup === false) {
            return false;
        }

        return $currentOwner === $owner && $currentGroup === $group;
    }

    private function permissionStatusForParent(string $parent, bool $pathExists): array
    {
        $hasPrivilege = $this->runningAsRoot() || $this->sudoEnabled();
        $status = $hasPrivilege ? 'needs_privilege' : 'not_writable';

        if ($hasPrivilege) {
            $message = $pathExists
                ? 'Directory is not writable by the web user. Privileged creation will be used to adjust ownership.'
                : 'Parent directory is not writable. Privileged creation will be used to create and chown this path.';
        } else {
            $message = $pathExists
                ? 'Directory exists but is not writable by the web user.'
                : 'Parent directory is not writable by the web user. Enable GWM_SUDO_ENABLED or adjust ownership.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'parent' => $parent,
        ];
    }

    private function resolveRepoPath(Project $project): string
    {
        $localPath = trim((string) $project->local_path);
        $repoPath = '';

        if ($localPath !== '' && $this->isPathWritableForGit($localPath)) {
            $this->ensurePath($localPath);
            $repoPath = $localPath;
        } elseif ($this->shouldUseFtpWorkspace($project)) {
            $repoPath = $this->ensureFtpWorkspace($project);
        } else {
            $this->ensurePath($localPath);
            $repoPath = $localPath;
        }

        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            if (! $project->repo_url) {
                throw new \RuntimeException('Repository URL is required to initialize git for this project.');
            }

            app(RepositoryBootstrapper::class)->bootstrap($project, $repoPath);
        }

        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            throw new \RuntimeException('No git repository found at: '.$repoPath);
        }

        return $repoPath;
    }

    private function shouldUseFtpWorkspace(Project $project): bool
    {
        return (bool) $project->ftp_enabled && ! $project->ssh_enabled;
    }

    private function ensureFtpWorkspace(Project $project): string
    {
        $path = $this->ftpWorkspacePath($project);
        $this->ensurePath($path);
        return $path;
    }

    private function ftpWorkspacePath(Project $project): string
    {
        $root = trim((string) config('gitmanager.ftp.workspace_path', storage_path('app/ftp-workspaces')));
        if ($root === '') {
            $root = storage_path('app/ftp-workspaces');
        }
        if (! $this->isAbsolutePath($root)) {
            $root = base_path($root);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $root.DIRECTORY_SEPARATOR.$project->id;
    }

    private function isPathWritableForGit(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (is_dir($path)) {
            if (! is_writable($path)) {
                return false;
            }

            $gitDir = $path.DIRECTORY_SEPARATOR.'.git';
            if (is_dir($gitDir) && ! is_writable($gitDir)) {
                return false;
            }

            return true;
        }

        $parent = $this->closestExistingParent($path);
        return $parent !== null && is_writable($parent);
    }

    private function resolveExecutionPath(Project $project, string $repoPath): string
    {
        $laravelRoot = $this->findLaravelRoot($repoPath)
            ?? $this->findLaravelRoot($project->local_path);

        return $laravelRoot ?: $repoPath;
    }

    private function findGitRoot(string $path): ?string
    {
        $candidate = $path.DIRECTORY_SEPARATOR.'.git';
        return is_dir($candidate) ? $path : null;
    }

    private function ensureCleanWorkingTree(string $repoPath, array &$output, bool $strict = false): void
    {
        if (! $strict) {
            return;
        }

        if ($this->workingTreeDirty($repoPath, $output)) {
            throw new \RuntimeException('Working tree has uncommitted changes. Resolve them before deploying.');
        }
    }

    private function stashIfDirty(string $repoPath, array &$output): bool
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return false;
        }

        if (! $this->tryRevParse($repoPath)) {
            $output[] = 'Local changes detected, but no initial commit exists. Skipping stash.';
            return false;
        }

        $output[] = 'Local changes detected: stashing tracked changes before deploy.';
        $process = $this->runProcess([
            'git',
            '-C',
            $repoPath,
            'stash',
            'push',
            '-m',
            'gwm-deploy',
            '--',
            '.',
            ':(exclude).htaccess',
            ':(exclude)public/.htaccess',
        ], $output, null, false);

        if (! $process->isSuccessful()) {
            $output[] = 'Warning: unable to stash local changes.';
            return false;
        }

        return true;
    }

    private function restoreStashOrReset(string $repoPath, array &$output, ?string $resetTo = null): void
    {
        $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
        if ($pop->isSuccessful()) {
            return;
        }

        $output[] = 'Warning: stashed changes could not be restored. Leaving stash intact and resetting working tree.';
        $target = $resetTo ?: 'HEAD';
        $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $target], $output, null, false);
    }

    private function resetToRemote(Project $project, string $repoPath, string $branch, array &$output, bool $forceClean): void
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status !== '') {
            $output[] = $forceClean
                ? 'Force deploy requested: tracked files will be reset and untracked files removed.'
                : 'Local changes detected: tracked files will be reset, untracked files preserved.';
        }

        $preservePath = $this->snapshotPreservePaths($project, $repoPath, $output);
        $untrackedBackup = null;

        try {
            $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', 'origin/'.$branch], $output);
        } catch (ProcessFailedException $exception) {
            $paths = $this->extractUntrackedOverwritePaths($exception);
            if ($paths === []) {
                throw $exception;
            }

            $output[] = 'Reset blocked by untracked files. Backing them up before retry.';
            $untrackedBackup = $this->backupUntrackedPaths($project, $repoPath, $paths, $output);
            if (! $untrackedBackup) {
                throw new \RuntimeException('Unable to backup untracked files blocking reset.');
            }
            $this->removeUntrackedPaths($repoPath, $paths, $output);

            $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', 'origin/'.$branch], $output);
        }

        if ($forceClean) {
            $this->runProcess($this->gitCleanCommand($project, $repoPath, false), $output);
        }

        $this->restorePreservedPaths($repoPath, $preservePath, $output);

        if ($untrackedBackup) {
            if ($forceClean) {
                $output[] = 'Untracked conflict backup kept at: '.$untrackedBackup;
            } else {
                $this->restoreUntrackedBackup($repoPath, $untrackedBackup, $output);
            }
        }
    }

    private function forceCleanWorkingTree(Project $project, string $repoPath, array &$output): void
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return;
        }

        $output[] = 'Force deploy requested: cleaning working tree.';
        $output[] = 'Audit: git status --porcelain';
        foreach (explode("\n", $status) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $output[] = $line;
            }
        }

        $cleanPreview = $this->runProcess($this->gitCleanCommand($project, $repoPath, true), $output, null, false);
        if ($cleanPreview->isSuccessful()) {
            $previewLines = array_filter(array_map('trim', explode("\n", $cleanPreview->getOutput())));
            if ($previewLines) {
                $output[] = 'Audit: git clean -fdn';
                foreach ($previewLines as $line) {
                    $output[] = $line;
                }
            }
        }

        $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard'], $output);
        $this->runProcess($this->gitCleanCommand($project, $repoPath, false), $output);
    }

    /**
     * @return array<int, string>
     */
    private function gitCleanCommand(Project $project, string $repoPath, bool $dryRun): array
    {
        $command = ['git', '-C', $repoPath, 'clean', $dryRun ? '-fdn' : '-fd'];

        $excludePaths = array_merge(['storage', '.htaccess', 'public/.htaccess'], $this->parseExcludePaths($project));
        foreach (array_unique($excludePaths) as $path) {
            $path = trim($path);
            if ($path === '' || $path === '.' || $path === '..') {
                continue;
            }

            $path = ltrim($path, '/\\');
            if ($path === '') {
                continue;
            }

            $command[] = '-e';
            $command[] = $path;
        }

        return $command;
    }

    private function snapshotPreservePaths(Project $project, string $repoPath, array &$output): ?string
    {
        $preserve = $this->getPreservePaths($project);
        if ($preserve === []) {
            return null;
        }

        $base = storage_path('app/deploy-preserve/'.$project->id.'/'.now()->format('Ymd_His'));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            $output[] = 'Unable to create preserve directory: '.$base;
            return null;
        }

        $matches = $this->collectPreserveTargets($repoPath, $preserve);
        if ($matches === []) {
            return null;
        }

        $output[] = 'Preserving '.count($matches).' path(s) before reset.';

        foreach ($matches as $relative) {
            $source = $repoPath.DIRECTORY_SEPARATOR.$relative;
            $destination = $base.DIRECTORY_SEPARATOR.$relative;
            $this->copyPath($source, $destination);
        }

        return $base;
    }

    private function restorePreservedPaths(string $repoPath, ?string $preservePath, array &$output): void
    {
        if (! $preservePath || ! is_dir($preservePath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($preservePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            $target = $repoPath.DIRECTORY_SEPARATOR.$relative;

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0775, true);
                }
                continue;
            }

            $targetDir = dirname($target);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }
            copy($item->getPathname(), $target);
        }

        $output[] = 'Restored preserved paths.';
    }

    /**
     * @return array<int, string>
     */
    private function extractUntrackedOverwritePaths(ProcessFailedException $exception): array
    {
        $text = trim($exception->getProcess()->getErrorOutput());
        if ($text === '') {
            $text = trim($exception->getProcess()->getOutput());
        }

        if ($text === '' || ! str_contains($text, 'untracked working tree files would be overwritten')) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $collect = false;
        $paths = [];

        foreach ($lines as $line) {
            if (! $collect && str_contains($line, 'would be overwritten')) {
                $collect = true;
                continue;
            }

            if (! $collect) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, 'Please ') || str_starts_with($trimmed, 'Aborting') || str_starts_with($trimmed, 'error:')) {
                break;
            }

            $path = $this->sanitizeUntrackedPath($trimmed);
            if ($path === null) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private function sanitizeUntrackedPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_contains($path, ' -> ')) {
            $parts = explode(' -> ', $path);
            $path = trim(end($parts));
        }

        $path = ltrim($path, "./");
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        if ($path === '' || $path === '.' || $path === '..') {
            return null;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '/').'/', $path) === 1) {
            return null;
        }

        if (str_contains($path, '..'.DIRECTORY_SEPARATOR) || str_starts_with($path, '..')) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<int, string> $paths
     */
    private function backupUntrackedPaths(Project $project, string $repoPath, array $paths, array &$output): ?string
    {
        if ($paths === []) {
            return null;
        }

        $base = storage_path('app/deploy-untracked/'.$project->id.'/'.now()->format('Ymd_His'));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            $output[] = 'Unable to create untracked backup directory: '.$base;
            return null;
        }

        $output[] = 'Backing up '.count($paths).' untracked path(s) blocking reset.';

        foreach ($paths as $relative) {
            $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $source = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (! file_exists($source)) {
                continue;
            }

            $destination = $base.DIRECTORY_SEPARATOR.$relative;
            $this->copyPath($source, $destination);
        }

        return $base;
    }

    /**
     * @param array<int, string> $paths
     */
    private function removeUntrackedPaths(string $repoPath, array $paths, array &$output): void
    {
        foreach ($paths as $relative) {
            $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $path = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $output[] = 'Removed untracked files blocking reset.';
    }

    private function restoreUntrackedBackup(string $repoPath, string $backupPath, array &$output): void
    {
        if (! is_dir($backupPath)) {
            return;
        }

        $restored = 0;
        $skipped = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            $target = $repoPath.DIRECTORY_SEPARATOR.$relative;

            if (file_exists($target)) {
                $skipped++;
                continue;
            }

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0775, true);
                }
                continue;
            }

            $targetDir = dirname($target);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            copy($item->getPathname(), $target);
            $restored++;
        }

        if ($restored > 0) {
            $output[] = 'Restored '.$restored.' untracked path(s) after reset.';
        }
        if ($skipped > 0) {
            $output[] = 'Skipped '.$skipped.' untracked path(s) because targets now exist. Backup kept at: '.$backupPath;
        }
    }

    /**
     * @return array<int, string>
     */
    private function getPreservePaths(Project $project): array
    {
        $paths = ['.htaccess', 'public/.htaccess'];

        foreach ($this->parseExcludePaths($project) as $path) {
            $paths[] = $path;
        }

        return array_values(array_unique(array_filter($paths, fn (string $path) => trim($path) !== '')));
    }

    /**
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    private function collectPreserveTargets(string $repoPath, array $patterns): array
    {
        $matches = [];
        $hasWildcard = false;
        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                $hasWildcard = true;
                break;
            }
        }

        $normalizedPatterns = array_map(function (string $pattern): string {
            $pattern = ltrim($pattern, '/\\');
            return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $pattern);
        }, $patterns);

        $direct = [];
        foreach ($normalizedPatterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                $direct[] = $pattern;
            }
        }

        foreach ($direct as $path) {
            $full = $repoPath.DIRECTORY_SEPARATOR.$path;
            if (file_exists($full)) {
                $matches[] = $path;
            }
        }

        if (! $hasWildcard) {
            return array_values(array_unique($matches));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() || $item->isLink()) {
                continue;
            }

            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

            foreach ($normalizedPatterns as $pattern) {
                if (str_contains($pattern, '*') && fnmatch($pattern, $relative)) {
                    $matches[] = $relative;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    private function copyPath(string $source, string $destination): void
    {
        if (is_link($source)) {
            return;
        }

        if (is_dir($source)) {
            if (! is_dir($destination)) {
                mkdir($destination, 0775, true);
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }

                $relative = $iterator->getSubPathname();
                $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
                $target = $destination.DIRECTORY_SEPARATOR.$relative;

                if ($item->isDir()) {
                    if (! is_dir($target)) {
                        mkdir($target, 0775, true);
                    }
                    continue;
                }

                $targetDir = dirname($target);
                if (! is_dir($targetDir)) {
                    mkdir($targetDir, 0775, true);
                }
                copy($item->getPathname(), $target);
            }

            return;
        }

        $targetDir = dirname($destination);
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        copy($source, $destination);
    }

    /**
     * @return array<int, string>
     */
    private function parseExcludePaths(Project $project): array
    {
        $raw = (string) ($project->exclude_paths ?? '');
        if ($raw === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $normalized = str_replace(',', "\n", $normalized);

        $paths = [];
        foreach (explode("\n", $normalized) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $paths[] = $line;
        }

        return $paths;
    }

    private function workingTreeDirty(string $repoPath, array &$output): bool
    {
        return $this->getWorkingTreeStatus($repoPath, $output) !== '';
    }

    private function getWorkingTreeStatus(string $repoPath, array &$output): string
    {
        $process = $this->runProcess(['git', '-C', $repoPath, 'status', '--porcelain'], $output, null, false);
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Unable to check git status for this project.');
        }

        return trim($process->getOutput());
    }

    private function tryRevParse(string $repoPath): ?string
    {
        $output = [];
        $process = $this->runProcess(['git', '-C', $repoPath, 'rev-parse', '--verify', 'HEAD'], $output, null, false);
        if (! $process->isSuccessful()) {
            return null;
        }

        $hash = trim($process->getOutput());
        return $hash !== '' ? $hash : null;
    }

    private function runWithSingleRetry(callable $callback, array &$output, string $label, ?callable $shouldRetry = null): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            if ($shouldRetry && ! $shouldRetry($exception)) {
                throw $exception;
            }
            $output[] = $label.' failed. Retrying once.';
            $callback();
        }
    }

    private function beginDeploymentStream(Deployment $deployment): void
    {
        $this->activeDeployment = $deployment;
        $this->lastOutputCount = 0;
        $this->lastStreamAt = microtime(true);
        $this->processCounter = 0;
        $this->activeDeployment->output_log = '';
        $this->activeDeployment->save();
    }

    private function endDeploymentStream(): void
    {
        $this->activeDeployment = null;
        $this->lastOutputCount = 0;
        $this->lastStreamAt = 0.0;
    }

    private function maybeStreamOutput(array $output, bool $force = false): void
    {
        if (! $this->activeDeployment) {
            return;
        }

        $count = count($output);
        if (! $force) {
            if ($count === $this->lastOutputCount) {
                return;
            }

            $now = microtime(true);
            if (($count - $this->lastOutputCount) < 5 && ($now - $this->lastStreamAt) < 2.0) {
                return;
            }

            $this->lastStreamAt = $now;
        } else {
            $this->lastStreamAt = microtime(true);
        }

        $this->lastOutputCount = $count;
        $this->activeDeployment->output_log = implode("\n", $output);
        $this->activeDeployment->save();
    }

    private function runMaintenanceAction(Project $project, ?User $user, string $action, callable $callback, bool $runClearCache = false): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => $action,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];

        try {
            $callback($executionPath, $output);
            if ($runClearCache) {
                $this->maybeRunLaravelClearCache($project, $output);
            }

            $deployment->status = 'success';
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_error_message = null;
            $project->save();
            $this->endDeploymentStream();
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
            $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_error_message = $exception->getMessage();
            $project->save();
            $this->endDeploymentStream();
        }

        return $deployment;
    }

    /**
     * @return array<int, array{hash: string, short: string, author: string, date: string, message: string}>
     */
    public function getRecentCommits(Project $project, int $limit = 3): array
    {
        $repoPath = $this->getRepoPathIfExists($project);
        if (! $repoPath) {
            return [];
        }

        $output = [];
        $process = $this->runProcess([
            'git',
            '-C',
            $repoPath,
            'log',
            '-n',
            (string) $limit,
            '--pretty=format:%H|%h|%an|%ad|%s',
            '--date=iso',
        ], $output, null, false);

        if (! $process->isSuccessful()) {
            return [];
        }

        $lines = array_filter(array_map('trim', explode("\n", $process->getOutput())));
        $commits = [];
        foreach ($lines as $line) {
            [$hash, $short, $author, $date, $message] = array_pad(explode('|', $line, 5), 5, '');
            if ($hash === '') {
                continue;
            }
            $commits[] = [
                'hash' => $hash,
                'short' => $short ?: substr($hash, 0, 7),
                'author' => $author,
                'date' => $date,
                'message' => $message,
            ];
        }

        return $commits;
    }

    public function getCurrentHead(Project $project): ?string
    {
        $repoPath = $this->getRepoPathIfExists($project);
        if (! $repoPath) {
            return null;
        }

        return $this->tryRevParse($repoPath);
    }

    private function getRepoPathIfExists(Project $project): ?string
    {
        $localPath = trim((string) $project->local_path);
        if ($localPath !== '' && is_dir($localPath.DIRECTORY_SEPARATOR.'.git')) {
            return $localPath;
        }

        if (! $this->shouldUseFtpWorkspace($project)) {
            return null;
        }

        $workspace = $this->ftpWorkspacePath($project);
        return is_dir($workspace.DIRECTORY_SEPARATOR.'.git') ? $workspace : null;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function runStagingChecks(Project $project, string $repoPath, ?string $hash, array &$output): void
    {
        if (! $this->stagingEnabled() || ! $hash) {
            return;
        }

        $base = rtrim((string) config('gitmanager.deploy_staging.path', storage_path('app/deploy-staging')), DIRECTORY_SEPARATOR);
        if ($base === '') {
            return;
        }
        if (! $this->isAbsolutePath($base)) {
            $base = base_path($base);
        }

        $stageRoot = $base.DIRECTORY_SEPARATOR.$project->id;
        $stagePath = $stageRoot.DIRECTORY_SEPARATOR.now()->format('Ymd_His');
        $output[] = 'Running staged deployment checks.';

        if (! is_dir($stageRoot)) {
            mkdir($stageRoot, 0775, true);
        }

        try {
            if (is_dir($stagePath)) {
                $this->runProcess(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $stagePath], $output, null, false);
                $this->deleteDirectory($stagePath);
            }

            $this->runProcess(['git', '-C', $repoPath, 'worktree', 'add', '--force', $stagePath, $hash], $output);

            $this->runWithSingleRetry(function () use ($project, $stagePath, &$output): void {
                if ($project->run_composer_install) {
                    $this->runComposerCommandWithFallback(
                        $stagePath,
                        $output,
                        'Staging composer install',
                        ['composer', 'install', '--no-dev', '--optimize-autoloader']
                    );
                }

                if ($project->run_npm_install) {
                    $this->runNpmInstallWithFallback(
                        $stagePath,
                        $output,
                        'Staging npm install',
                        $this->npmInstallCommand($stagePath)
                    );
                }

                if ($project->run_build_command && $project->build_command) {
                    $this->ensureBuildOutputWritable($stagePath, $output);
                    $this->logStep($output, 'Staging build command', $stagePath, $project->build_command);
                    $this->runProjectShellCommand($project->build_command, $output, $stagePath);
                }

                if ($project->run_test_command && $project->test_command) {
                    $this->logStep($output, 'Staging test command', $stagePath, $project->test_command);
                    $this->runTestCommand($project, $project->test_command, $stagePath, $output);
                }
            }, $output, 'Staged deploy checks');

            $output[] = 'Staged deployment checks passed.';
        } finally {
            $this->runProcess(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $stagePath], $output, null, false);
            $this->deleteDirectory($stagePath);
        }
    }

    private function stagingEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_staging.enabled', true);
    }

    private function appendWorkflowOutput(Deployment $deployment, Project $project, array &$output): void
    {
        try {
            $messages = app(WorkflowService::class)->handleDeployment($deployment, $project);
            foreach ($messages as $message) {
                $output[] = $message;
            }
        } catch (\Throwable $exception) {
            $output[] = 'Workflow notifications failed: '.$exception->getMessage();
        }
    }

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $processId = $this->logProcessStart($command, $workingDir, $output);
        $process = new Process($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runProjectProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $processId = $this->logProcessStart($command, $workingDir, $output);
        $process = new Process($command, $workingDir, $this->projectEnvForPath($workingDir));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runProjectProcessWithEnv(array $command, array &$output = [], ?string $workingDir = null, array $extraEnv = [], bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $env = array_merge($this->projectEnvForPath($workingDir), $extraEnv);
        $processId = $this->logProcessStart($command, $workingDir, $output);
        $process = new Process($command, $workingDir, $env);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runProjectShellCommand(string $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $trimmed = ltrim($command);
        if (str_starts_with($trimmed, 'php ')) {
            $command = $this->phpBinary().' '.substr($trimmed, 4);
        }

        $processId = $this->logProcessStartShell($command, $workingDir, $output);
        $process = Process::fromShellCommandline($command, $workingDir, $this->projectEnvForPath($workingDir));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runShellCommand(string $command, array &$output = [], ?string $workingDir = null): Process
    {
        $trimmed = ltrim($command);
        if (str_starts_with($trimmed, 'php ')) {
            $command = $this->phpBinary().' '.substr($trimmed, 4);
        }

        $processId = $this->logProcessStartShell($command, $workingDir, $output);
        $process = Process::fromShellCommandline($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function normalizeCommand(array $command): array
    {
        $binary = $command[0] ?? '';
        if ($binary === 'composer') {
            $composerPath = $this->resolveComposerBinaryPath();
            $phpBinary = $this->phpBinary();
            if ($composerPath !== '' && $phpBinary !== '') {
                array_shift($command);
                array_unshift($command, $composerPath);
                array_unshift($command, $phpBinary);
                return $command;
            }
        }

        $command[0] = match ($binary) {
            'git' => $this->gitBinary(),
            'composer' => $this->composerBinary(),
            'npm' => $this->npmBinary(),
            'php' => $this->phpBinary(),
            default => $binary,
        };

        return $command;
    }

    private function logProcessStart(array $command, ?string $workingDir, array &$output): int
    {
        $this->processCounter++;
        $line = 'Process #'.$this->processCounter.' started: '.$this->formatCommand($command);
        if ($workingDir) {
            $line .= ' (path: '.$workingDir.')';
        }
        $output[] = $line;
        $this->maybeStreamOutput($output, true);

        return $this->processCounter;
    }

    private function logProcessStartShell(string $command, ?string $workingDir, array &$output): int
    {
        $this->processCounter++;
        $line = 'Process #'.$this->processCounter.' started: '.$command;
        if ($workingDir) {
            $line .= ' (path: '.$workingDir.')';
        }
        $output[] = $line;
        $this->maybeStreamOutput($output, true);

        return $this->processCounter;
    }

    private function logProcessEnd(int $processId, Process $process, array &$output): void
    {
        $code = $process->getExitCode();
        $output[] = 'Process #'.$processId.' finished with exit code '.($code ?? 'null').'.';
        $this->maybeStreamOutput($output, true);
    }

    private function formatCommand(array $command): string
    {
        return implode(' ', array_map(static function ($part) {
            $part = (string) $part;
            return str_contains($part, ' ') ? '"'.$part.'"' : $part;
        }, $command));
    }

    private function gitBinary(): string
    {
        $configured = trim((string) config('gitmanager.git_binary', 'git'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'git';
    }

    private function composerBinary(): string
    {
        $configured = trim((string) config('gitmanager.composer_binary', 'composer'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'composer';
    }

    private function resolveComposerBinaryPath(): string
    {
        $configured = trim($this->composerBinary());
        $configured = trim($configured, "\"' ");
        if ($configured === '') {
            return '';
        }

        if ($this->isAbsolutePath($configured) && file_exists($configured)) {
            return $configured;
        }

        if (str_contains($configured, DIRECTORY_SEPARATOR) && file_exists($configured)) {
            return $configured;
        }

        $resolved = $this->resolveBinaryFromPath($configured);
        return $resolved ?: '';
    }

    private function resolveBinaryFromPath(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }

        if ($this->isAbsolutePath($binary)) {
            return $binary;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && file_exists($binary)) {
            return $binary;
        }

        $path = getenv('PATH') ?: ($_SERVER['PATH'] ?? '');
        if ($path === '') {
            return null;
        }

        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $extensions = ['', '.exe', '.bat', '.cmd'];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }

            foreach ($extensions as $ext) {
                $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary.$ext;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function npmBinary(): string
    {
        $configured = trim((string) config('gitmanager.npm_binary', 'npm'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'npm';
    }

    private function phpBinary(): string
    {
        $override = $this->resolveEnvOverride([
            'GWM_PHP_PATH',
            'GWM_PHP_BINARY',
            'GPM_PHP_PATH',
            'GPM_PHP_BINARY',
        ]);
        if ($override !== null) {
            return $override;
        }

        $configured = trim((string) config('gitmanager.php_binary', 'php'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'php';
    }

    private function resolveEnvOverride(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->readEnvOverride($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function readEnvOverride(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? ($_ENV[$key] ?? '');
        }

        $value = is_string($value) ? trim($value) : '';
        if ($value !== '') {
            return $value;
        }

        $fromEnvFile = $this->getManagerEnvValue($key);
        $fromEnvFile = $fromEnvFile !== null ? trim($fromEnvFile) : '';

        return $fromEnvFile !== '' ? $fromEnvFile : null;
    }

    private function gitEnv(): array
    {
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        $token = trim((string) config('services.github.token', env('GITHUB_TOKEN')));
        if ($token === '') {
            return $env;
        }

        $askPass = $this->ensureAskPassScript();
        if ($askPass) {
            $env['GIT_ASKPASS'] = $askPass;
            $env['GIT_USERNAME'] = 'x-access-token';
            $env['GIT_PASSWORD'] = $token;
        }

        return $env;
    }

    private function baseEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];

        $extraPath = trim((string) config('gitmanager.process_path', ''));
        $extraPath = trim($extraPath, "\"' ");
        if ($extraPath !== '') {
            $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
            $current = $env[$pathKey] ?? '';
            $env[$pathKey] = $extraPath.PATH_SEPARATOR.$current;
        }

        $phpBinary = $this->phpBinary();
        if (str_contains($phpBinary, DIRECTORY_SEPARATOR) || str_contains($phpBinary, '/')) {
            $phpDir = dirname($phpBinary);
            if ($phpDir !== '' && $phpDir !== '.') {
                $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
                $current = $env[$pathKey] ?? '';
                if (! str_contains($current, $phpDir)) {
                    $env[$pathKey] = $phpDir.PATH_SEPARATOR.$current;
                }
            }
        }

        return $env;
    }

    private function projectEnv(): array
    {
        $env = $this->baseEnv();
        $blockedPrefixes = [
            'APP_',
            'DB_',
            'CACHE_',
            'SESSION_',
            'QUEUE_',
            'REDIS_',
            'MAIL_',
            'FILESYSTEM_',
            'LOG_',
            'BROADCAST_',
            'MEMCACHED_',
        ];

        foreach (array_keys($env) as $key) {
            $upperKey = strtoupper((string) $key);
            foreach ($blockedPrefixes as $prefix) {
                if (str_starts_with($upperKey, $prefix)) {
                    unset($env[$key]);
                    break;
                }
            }
        }

        return $env;
    }

    private function projectEnvForPath(?string $workingDir): array
    {
        $env = $this->projectEnv();
        if (! $workingDir) {
            return $env;
        }

        $envRoot = $this->findEnvRoot($workingDir);
        if (! $envRoot) {
            return $env;
        }

        $overrides = $this->readEnvFile($envRoot.DIRECTORY_SEPARATOR.'.env');
        if ($overrides === []) {
            return $env;
        }

        $allowedPrefixes = [
            'APP_',
            'DB_',
            'CACHE_',
            'SESSION_',
            'QUEUE_',
            'REDIS_',
            'MAIL_',
            'FILESYSTEM_',
            'LOG_',
            'BROADCAST_',
            'MEMCACHED_',
        ];

        foreach ($overrides as $key => $value) {
            $upperKey = strtoupper((string) $key);
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($upperKey, $prefix)) {
                    $env[$key] = $value;
                    break;
                }
            }
        }

        return $env;
    }

    private function findEnvRoot(string $path): ?string
    {
        return $this->walkUpForMarker($path, '.env');
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*(.*)$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            $value = trim($matches[2]);

            if ($value === '') {
                $values[$key] = '';
                continue;
            }

            $firstChar = $value[0];
            $lastChar = substr($value, -1);
            if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                $value = substr($value, 1, -1);
                if ($firstChar === '"') {
                    $value = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
                }
            } else {
                if (str_contains($value, ' #')) {
                    $value = explode(' #', $value, 2)[0];
                } elseif (str_contains($value, "\t#")) {
                    $value = explode("\t#", $value, 2)[0];
                }
            }

            $values[$key] = trim($value);
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function npmInstallCommand(string $path): array
    {
        if (is_file($path.DIRECTORY_SEPARATOR.'package-lock.json')) {
            return ['npm', 'ci'];
        }

        return ['npm', 'install'];
    }

    private function ensureAskPassScript(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach ($this->askPassDirectories() as $directory) {
                $path = $this->writeAskPassScript($directory, 'bat');
                if ($path !== '') {
                    return $path;
                }
            }

            return null;
        }

        foreach ($this->askPassDirectories() as $directory) {
            $path = $this->writeAskPassScript($directory, 'sh', $directory === sys_get_temp_dir());
            if ($this->isAskPassExecutable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function writeAskPassScript(string $directory, string $extension, bool $unique = false): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            return '';
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = $unique ? 'git-askpass-'.uniqid('', true).'.'.$extension : 'git-askpass.'.$extension;
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        if ($extension === 'bat') {
            if (! file_exists($path)) {
                file_put_contents($path, "@echo off\r\n"
                    ."echo %* | findstr /I \"Username\" >nul\r\n"
                    ."if %errorlevel%==0 (\r\n"
                    ."  echo %GIT_USERNAME%\r\n"
                    .") else (\r\n"
                    ."  echo %GIT_PASSWORD%\r\n"
                    .")\r\n");
            }

            return $path;
        }

        if (! file_exists($path)) {
            file_put_contents($path, "#!/bin/sh\n"
                ."case \"$1\" in\n"
                .'  *Username*) echo "${GIT_USERNAME:-}";;'."\n"
                .'  *) echo "${GIT_PASSWORD:-}";;'."\n"
                ."esac\n");
        }

        @chmod($path, 0700);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function askPassDirectories(): array
    {
        $paths = [];
        $configured = trim((string) config('gitmanager.askpass_dir', ''));
        if ($configured !== '') {
            $paths[] = $configured;
        }

        $paths[] = storage_path('app');
        $paths[] = sys_get_temp_dir();

        return array_values(array_unique(array_filter($paths)));
    }

    private function isAskPassExecutable(?string $path): bool
    {
        if (! $path || ! file_exists($path)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        if (! is_executable($path)) {
            return false;
        }

        $process = new Process([$path, 'Username'], null, array_merge($this->baseEnv(), [
            'GIT_USERNAME' => 'x-access-token',
            'GIT_PASSWORD' => 'token',
        ]));
        $process->run();

        return $process->isSuccessful();
    }

    private function maybeRunLaravelClearCache(Project $project, array &$output): void
    {
        $laravelRoot = $this->findLaravelRoot($project->local_path);
        if (! $laravelRoot) {
            return;
        }

        if (! $this->artisanCommandExists($laravelRoot, 'app:clear-cache', $output)) {
            return;
        }

        $this->clearLaravelConfigCache($laravelRoot, $output);

        $connection = $this->getLaravelEnvValue($laravelRoot, 'DB_CONNECTION');
        if ($connection === null || trim($connection) === '') {
            $output[] = 'Skipping app:clear-cache: DB_CONNECTION not set in project .env.';
            return;
        }

        if (strtolower($connection) === 'sqlite') {
            $database = $this->getLaravelEnvValue($laravelRoot, 'DB_DATABASE');
            if ($database === null || trim($database) === '') {
                $output[] = 'Skipping app:clear-cache: sqlite database path not set.';
                return;
            }

            $database = trim($database);
            if ($database !== ':memory:') {
                $resolved = $this->isAbsolutePath($database)
                    ? $database
                    : $laravelRoot.DIRECTORY_SEPARATOR.$database;

                if (! file_exists($resolved)) {
                    $output[] = 'Skipping app:clear-cache: sqlite database file not found at '.$resolved;
                    return;
                }
            }
        }

        try {
            $this->runProjectProcess(['php', 'artisan', 'app:clear-cache'], $output, $laravelRoot);
        } catch (\Throwable $exception) {
            $output[] = 'Warning: app:clear-cache failed: '.$exception->getMessage();
        }
    }

    private function artisanCommandExists(string $path, string $command, array &$output): bool
    {
        $process = $this->runProjectProcess(['php', 'artisan', 'list', '--format=json'], $output, $path, false);

        if (! $process->isSuccessful()) {
            return false;
        }

        $payload = json_decode($process->getOutput(), true);
        if (! is_array($payload)) {
            return false;
        }

        $commands = $payload['commands'] ?? [];
        foreach ($commands as $entry) {
            if (($entry['name'] ?? null) === $command) {
                return true;
            }
        }

        return false;
    }

    private function runTestCommand(Project $project, string $command, string $path, array &$output): void
    {
        if ($this->isArtisanTestCommand($command)) {
            $laravelRoot = $this->findLaravelRoot($path) ?? $this->findLaravelRoot($project->local_path);
            if (! $laravelRoot) {
                $output[] = 'Skipping tests: Laravel app not detected for artisan test.';
                return;
            }

            if (! $this->artisanCommandExists($laravelRoot, 'test', $output)) {
                $output[] = 'Skipping tests: artisan test not available (dev dependencies may be missing).';
                return;
            }
        }

        $this->runProjectShellCommand($command, $output, $path);
    }

    private function isArtisanTestCommand(string $command): bool
    {
        $normalized = strtolower(trim($command));

        return (bool) preg_match('/(^|\\s)artisan\\s+test(\\s|$)/', $normalized);
    }

    private function logStep(array &$output, string $label, string $path, string $command): void
    {
        $output[] = $label.' (path: '.$path.')';
        $output[] = '$ '.$command;
        $this->maybeStreamOutput($output);
    }

    private function runComposerCommandWithFallback(string $path, array &$output, string $label, array $command, bool $forceStaged = false): void
    {
        $vendorPath = $path.DIRECTORY_SEPARATOR.'vendor';
        $projectWritable = is_writable($path);
        $vendorWritable = is_dir($vendorPath) ? is_writable($vendorPath) : $projectWritable;
        $phpBinary = $this->phpBinary();
        if ($phpBinary !== '') {
            $output[] = 'Composer will use PHP binary: '.$phpBinary;
        }

        $this->ensureWritableDirectory($path, $output, 'Project directory');
        if ($forceStaged) {
            $output[] = 'Running composer command using staged install.';
        } elseif ($vendorWritable) {
            $this->ensureWritableDirectory($vendorPath, $output, 'Vendor directory');
            try {
                $this->logStep($output, $label, $path, implode(' ', $command));
                $this->runProjectProcess($command, $output, $path);
                return;
            } catch (ProcessFailedException $exception) {
                if (! $this->isPermissionError($exception, $output)) {
                    throw $exception;
                }

                $output[] = 'Composer command failed with permission errors. Attempting staged install.';
            }
        } else {
            $output[] = 'Vendor directory is not writable. Attempting staged install.';
        }

        $tempRoot = $this->createTempPath($path, 'composer');
        $tempVendor = $tempRoot.DIRECTORY_SEPARATOR.'vendor';
        $this->ensurePath($tempRoot);

        try {
            $this->logStep($output, $label.' (staged)', $path, implode(' ', $command));
            $this->runProjectProcessWithEnv($command, $output, $path, [
                'COMPOSER_VENDOR_DIR' => $tempVendor,
            ]);

            if (! is_dir($tempVendor)) {
                throw new \RuntimeException('Staged composer install did not create a vendor directory.');
            }

            $this->swapDirectory($tempVendor, $vendorPath, $output, 'Vendor directory');
            $this->applyOwnershipFromReference($vendorPath, $path, $output, 'Vendor directory');
        } catch (\Throwable $exception) {
            if ($this->shouldAttemptComposerCleanReinstall($exception, $output)) {
                $output[] = 'Staged composer install failed due to permissions. Attempting clean reinstall.';
                $this->attemptComposerCleanReinstall($path, $output, $label, $command);
                return;
            }

            throw $exception;
        } finally {
            if (is_dir($tempRoot)) {
                $this->deleteDirectory($tempRoot);
            }
        }
    }

    private function runNpmInstallWithFallback(string $path, array &$output, string $label, array $command, bool $forceStaged = false): void
    {
        $this->runNpmCommandWithFallback($path, $output, $label, $command, false, $forceStaged);
    }

    private function runNpmCommandWithFallback(string $path, array &$output, string $label, array $command, bool $syncManifestFiles, bool $forceStaged = false): void
    {
        $modulesPath = $path.DIRECTORY_SEPARATOR.'node_modules';
        $projectWritable = is_writable($path);
        $modulesWritable = is_dir($modulesPath) ? is_writable($modulesPath) : $projectWritable;
        $commandVerb = strtolower((string) ($command[1] ?? ''));

        $this->ensureWritableDirectory($path, $output, 'Project directory');
        $useStagedInstall = $forceStaged || ($commandVerb === 'ci' && is_dir($modulesPath));
        if ($useStagedInstall) {
            $output[] = $forceStaged
                ? 'Running npm command using staged install.'
                : 'Node modules directory exists. Running npm ci in staged mode to avoid permission conflicts.';
        }
        if ($modulesWritable && ! $useStagedInstall) {
            $this->ensureWritableDirectory($modulesPath, $output, 'Node modules directory');
            try {
                $this->logStep($output, $label, $path, implode(' ', $command));
                $this->runProjectProcess($command, $output, $path);
                return;
            } catch (ProcessFailedException $exception) {
                if (! $this->isPermissionError($exception, $output)) {
                    throw $exception;
                }

                $output[] = 'Npm command failed with permission errors. Attempting staged install.';
            }
        }

        if (! $useStagedInstall) {
            $output[] = 'Node modules directory is not writable. Attempting staged install.';
        }
        $tempRoot = $this->createTempPath($path, 'npm');
        $this->ensurePath($tempRoot);
        $this->copyNpmManifestFiles($path, $tempRoot, $output);

        try {
            $this->logStep($output, $label.' (staged)', $tempRoot, implode(' ', $command));
            $this->runProjectProcess($command, $output, $tempRoot);

            $tempModules = $tempRoot.DIRECTORY_SEPARATOR.'node_modules';
            if (! is_dir($tempModules)) {
                throw new \RuntimeException('Staged npm install did not create node_modules.');
            }

            if ($syncManifestFiles) {
                $this->syncNpmManifestFiles($tempRoot, $path, $output);
            }

            $this->swapDirectory($tempModules, $modulesPath, $output, 'Node modules directory');
            $this->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
        } catch (\Throwable $exception) {
            if ($this->shouldAttemptNpmCleanReinstall($exception, $output)) {
                $output[] = 'Staged npm install failed due to permissions. Attempting clean reinstall.';
                $this->attemptNpmCleanReinstall($path, $output, $label, $command);
                return;
            }

            throw $exception;
        } finally {
            if (is_dir($tempRoot)) {
                $this->deleteDirectory($tempRoot);
            }
        }
    }

    private function copyNpmManifestFiles(string $sourceRoot, string $targetRoot, array &$output): void
    {
        $files = ['package.json', 'package-lock.json', 'npm-shrinkwrap.json'];
        foreach ($files as $file) {
            $source = $sourceRoot.DIRECTORY_SEPARATOR.$file;
            if (! is_file($source)) {
                continue;
            }

            $destination = $targetRoot.DIRECTORY_SEPARATOR.$file;
            if (! @copy($source, $destination)) {
                $output[] = 'Warning: unable to copy '.$file.' for staged npm install.';
            }
        }
    }

    private function syncNpmManifestFiles(string $sourceRoot, string $targetRoot, array &$output): void
    {
        $files = ['package.json', 'package-lock.json', 'npm-shrinkwrap.json'];
        foreach ($files as $file) {
            $source = $sourceRoot.DIRECTORY_SEPARATOR.$file;
            if (! is_file($source)) {
                continue;
            }

            $destination = $targetRoot.DIRECTORY_SEPARATOR.$file;
            if (! @copy($source, $destination)) {
                throw new \RuntimeException('Unable to update '.$file.' after staged npm run.');
            }

            $output[] = 'Updated '.$file.' from staged npm run.';
        }
    }

    private function createTempPath(string $root, string $prefix): string
    {
        $base = $root.DIRECTORY_SEPARATOR.'.gwm-staging';
        $this->ensurePath($base);

        return $base.DIRECTORY_SEPARATOR.$prefix.'-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function swapDirectory(string $source, string $destination, array &$output, string $label): void
    {
        $backup = null;
        $destinationParent = dirname($destination);
        if (! is_dir($destinationParent)) {
            $this->ensurePath($destinationParent);
        }

        if (is_dir($destination)) {
            $backup = $destination.'-gwm-backup-'.date('YmdHis');
            if (! @rename($destination, $backup)) {
                throw new \RuntimeException('Unable to move existing '.$label.' out of the way.');
            }
        }

        if (! @rename($source, $destination)) {
            if ($backup && is_dir($backup)) {
                @rename($backup, $destination);
            }
            throw new \RuntimeException('Unable to replace '.$label.' after staged install.');
        }

        if ($backup && is_dir($backup)) {
            $this->deleteDirectory($backup);
        }

        $output[] = 'Replaced '.$label.' using staged install.';
    }

    private function ensureWritableDirectory(string $path, array &$output, string $label): void
    {
        if (! is_dir($path)) {
            if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                $output[] = 'Warning: unable to create '.$label.': '.$path;
                return;
            }
        }

        if (! is_writable($path)) {
            @chmod($path, 0775);
        }

        if (! is_writable($path)) {
            $this->chmodRecursivePath($path, 0775, 0664, $output);
        }

        if (! is_writable($path)) {
            $output[] = 'Warning: '.$label.' is not writable: '.$path.'. Run Fix Permissions or ensure the web user owns this path.';
        }
    }

    private function attemptNpmCleanReinstall(string $path, array &$output, string $label, array $command): void
    {
        $modulesPath = $path.DIRECTORY_SEPARATOR.'node_modules';

        if (! is_dir($modulesPath)) {
            $output[] = 'Starting npm clean reinstall (no existing node_modules).';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            $this->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
            $output[] = 'Npm clean reinstall completed.';
            return;
        }

        $backup = $this->backupDirectory($modulesPath, $output, 'Node modules directory');
        if (! $backup) {
            throw new \RuntimeException('Unable to backup node_modules for clean reinstall.');
        }

        try {
            $output[] = 'Starting npm clean reinstall.';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
            $output[] = 'Npm clean reinstall completed.';
        } catch (\Throwable $exception) {
            if (is_dir($modulesPath)) {
                $this->deleteDirectory($modulesPath);
            }

            $this->restoreDirectoryBackup($backup, $modulesPath, $output, 'Node modules directory');
            $output[] = 'Npm clean reinstall failed; restored node_modules backup.';
            throw $exception;
        }

        $this->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
    }

    private function attemptComposerCleanReinstall(string $path, array &$output, string $label, array $command): void
    {
        $vendorPath = $path.DIRECTORY_SEPARATOR.'vendor';

        if (! is_dir($vendorPath)) {
            $output[] = 'Starting composer clean reinstall (no existing vendor directory).';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            $this->applyOwnershipFromReference($vendorPath, $path, $output, 'Vendor directory');
            $output[] = 'Composer clean reinstall completed.';
            return;
        }

        $backup = $this->backupDirectory($vendorPath, $output, 'Vendor directory');
        if (! $backup) {
            throw new \RuntimeException('Unable to backup vendor for clean reinstall.');
        }

        try {
            $output[] = 'Starting composer clean reinstall.';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
            $output[] = 'Composer clean reinstall completed.';
        } catch (\Throwable $exception) {
            if (is_dir($vendorPath)) {
                $this->deleteDirectory($vendorPath);
            }

            $this->restoreDirectoryBackup($backup, $vendorPath, $output, 'Vendor directory');
            $output[] = 'Composer clean reinstall failed; restored vendor backup.';
            throw $exception;
        }

        $this->applyOwnershipFromReference($vendorPath, $path, $output, 'Vendor directory');
    }

    private function shouldAttemptComposerCleanReinstall(\Throwable $exception, array $output): bool
    {
        if ($this->isPermissionError($exception, $output)) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        return str_contains($message, 'unable to move existing vendor directory')
            || str_contains($message, 'unable to replace vendor directory');
    }

    private function shouldAttemptNpmCleanReinstall(\Throwable $exception, array $output): bool
    {
        if ($this->isPermissionError($exception, $output)) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        return str_contains($message, 'unable to move existing node modules directory')
            || str_contains($message, 'unable to replace node modules directory');
    }

    private function backupDirectory(string $path, array &$output, string $label): ?string
    {
        if (! is_dir($path)) {
            return null;
        }

        $backup = $path.'-gwm-backup-'.date('YmdHis');
        if (@rename($path, $backup)) {
            $output[] = 'Backed up '.$label.' to '.$backup.'.';
            return $backup;
        }

        $output[] = 'Warning: unable to rename '.$label.' for backup. Attempting copy instead.';
        if (! $this->copyDirectory($path, $backup)) {
            $output[] = 'Warning: unable to copy '.$label.' for backup.';
            return null;
        }

        $this->deleteDirectory($path);
        $output[] = 'Copied '.$label.' to '.$backup.'.';
        return $backup;
    }

    private function restoreDirectoryBackup(string $backup, string $destination, array &$output, string $label): void
    {
        if (! is_dir($backup)) {
            return;
        }

        if (@rename($backup, $destination)) {
            $output[] = 'Restored '.$label.' from backup.';
            return;
        }

        $output[] = 'Warning: unable to rename backup for '.$label.'. Attempting copy instead.';
        if ($this->copyDirectory($backup, $destination)) {
            $this->deleteDirectory($backup);
            $output[] = 'Restored '.$label.' from backup.';
        }
    }

    private function copyDirectory(string $source, string $destination): bool
    {
        if (! is_dir($source)) {
            return false;
        }

        if (! is_dir($destination) && ! @mkdir($destination, 0775, true) && ! is_dir($destination)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0775, true) && ! is_dir($target)) {
                    return false;
                }
            } else {
                if (! @copy($item->getPathname(), $target)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function applyOwnershipFromReference(string $targetPath, string $referencePath, array &$output, string $label): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (! is_dir($targetPath)) {
            return;
        }

        $referencePath = is_dir($referencePath) ? $referencePath : dirname($referencePath);
        $owner = @fileowner($referencePath);
        $group = @filegroup($referencePath);
        if ($owner === false || $group === false) {
            $output[] = 'Warning: unable to read ownership for '.$label.'.';
            return;
        }

        if (function_exists('posix_geteuid')) {
            $euid = posix_geteuid();
            if ($euid !== 0 && $euid !== $owner) {
                $output[] = 'Warning: unable to change ownership for '.$label.' (not running as root).';
                return;
            }
        }

        $this->chownRecursivePath($targetPath, (int) $owner, (int) $group);
        $output[] = 'Aligned ownership for '.$label.' with project directory.';
    }

    private function chownRecursivePath(string $path, int $owner, int $group): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $target = $item->getPathname();
            @chown($target, $owner);
            @chgrp($target, $group);
        }

        @chown($path, $owner);
        @chgrp($path, $group);
    }

    private function attemptFixPermissions(Project $project, string $path, array &$output, bool $throwOnFailure): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output[] = 'Permission fix skipped on Windows.';
            $project->permissions_locked = false;
            $project->permissions_issue_message = null;
            $project->permissions_checked_at = now();
            $project->save();
            return true;
        }

        $laravelRoot = $this->findLaravelRoot($project->local_path)
            ?? $this->findLaravelRoot($path)
            ?? $path;
        $isLaravel = $this->findLaravelRoot($project->local_path) !== null || $this->findLaravelRoot($path) !== null;
        $needsComposer = $project->run_composer_install || is_file($laravelRoot.DIRECTORY_SEPARATOR.'composer.json');
        $needsNpm = $project->run_npm_install || is_file($laravelRoot.DIRECTORY_SEPARATOR.'package.json');
        $needsBuild = $project->run_build_command || is_dir($laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build');

        $targets = [
            ['label' => 'Project directory', 'path' => $laravelRoot, 'required' => true],
            ['label' => 'Vendor directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'vendor', 'required' => $needsComposer],
            ['label' => 'Storage directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'storage', 'required' => $isLaravel],
            ['label' => 'Bootstrap cache directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache', 'required' => $isLaravel],
            ['label' => 'Build directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build', 'required' => $needsBuild],
            ['label' => 'Build assets directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets', 'required' => $needsBuild],
            ['label' => 'Node modules directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'node_modules', 'required' => $needsNpm],
        ];

        $failures = [];
        foreach ($targets as $target) {
            $targetPath = $target['path'];
            if (! $target['required'] && ! is_dir($targetPath)) {
                continue;
            }

            $this->ensureWritableDirectory($targetPath, $output, $target['label']);
            if (! is_writable($targetPath)) {
                $failures[] = $target['label'].': '.$targetPath;
                continue;
            }

            $output[] = 'Adjusted permissions for: '.$targetPath;
        }

        $project->permissions_checked_at = now();
        if ($failures !== []) {
            $project->permissions_locked = true;
            $project->permissions_issue_message = 'Still not writable: '.implode(' | ', $failures);
            $project->save();

            if ($throwOnFailure) {
                throw new \RuntimeException('Permission fix incomplete. '.implode(' | ', $failures));
            }

            return false;
        }

        $project->permissions_locked = false;
        $project->permissions_issue_message = null;
        $project->save();

        return true;
    }

    private function needsPermissionFix(Project $project, string $executionPath): bool
    {
        if ($project->run_composer_install) {
            $vendorPath = $executionPath.DIRECTORY_SEPARATOR.'vendor';
            if ((is_dir($vendorPath) && ! is_writable($vendorPath)) || (! is_dir($vendorPath) && ! is_writable($executionPath))) {
                return true;
            }
        }

        if ($project->run_npm_install) {
            $modulesPath = $executionPath.DIRECTORY_SEPARATOR.'node_modules';
            if ((is_dir($modulesPath) && ! is_writable($modulesPath)) || (! is_dir($modulesPath) && ! is_writable($executionPath))) {
                return true;
            }
        }

        return false;
    }

    private function isPermissionError(\Throwable $exception, array $output): bool
    {
        $message = $exception->getMessage();
        if ($exception instanceof ProcessFailedException) {
            $process = $exception->getProcess();
            $message .= "\n".$process->getErrorOutput()."\n".$process->getOutput();
        }

        $haystack = strtolower($message."\n".implode("\n", $output));
        $needles = [
            'eacces',
            'eperm',
            'permission denied',
            'access denied',
            'operation was rejected by your operating system',
            'not permitted',
            'could not delete',
            'failed to delete',
            'failed to remove',
            'unlink',
        ];

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function ensureBuildOutputWritable(string $rootPath, array &$output): void
    {
        $buildPath = $rootPath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';
        $assetsPath = $buildPath.DIRECTORY_SEPARATOR.'assets';

        $this->ensureWritableDirectory($buildPath, $output, 'Build directory');
        $this->ensureWritableDirectory($assetsPath, $output, 'Build assets directory');
    }

    private function maybeSyncFtp(Project $project, string $executionPath, array &$output, array $extraExcludePaths = []): void
    {
        if (! $project->ftp_enabled) {
            return;
        }

        if ($project->ssh_enabled) {
            $output[] = 'FTPS sync skipped: SSH deployment is enabled for this project.';
            return;
        }

        $project->loadMissing('ftpAccount');
        if (! $project->ftpAccount) {
            $output[] = 'FTPS sync skipped: no FTP/SSH access record configured.';
            return;
        }

        $exclude = $this->ftpExcludePaths($project, $extraExcludePaths);
        app(FtpService::class)->sync($project, $executionPath, $exclude, $output);
    }

    /**
     * @return array<int, string>
     */
    private function ftpExcludePaths(Project $project, array $extraExcludePaths = []): array
    {
        $paths = [
            '.git',
            '.env',
            'storage',
            'bootstrap/cache',
        ];

        foreach ($this->parseExcludePaths($project) as $path) {
            $paths[] = $path;
        }

        foreach ($extraExcludePaths as $path) {
            $paths[] = $path;
        }

        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), fn (string $path) => $path !== '')));

        return $paths;
    }

    /**
     * @return array{skipComposerInstall: bool, skipNpmInstall: bool, excludePaths: array<int, string>}
     */
    private function planFtpOnlyDependencySync(Project $project, string $executionPath, array &$output): array
    {
        $plan = [
            'skipComposerInstall' => false,
            'skipNpmInstall' => false,
            'excludePaths' => [],
        ];

        if (! $project->ftp_enabled || $project->ssh_enabled) {
            return $plan;
        }

        $project->loadMissing('ftpAccount');
        if (! $project->ftpAccount) {
            $output[] = 'FTP-only pipeline skipped: no FTP/SSH access record configured.';
            return $plan;
        }

        $composerLockFiles = $this->collectManifestFiles($executionPath, ['composer.lock']);
        $composerManifestFiles = $this->collectManifestFiles($executionPath, ['composer.json']);
        $composerFiles = $composerLockFiles !== [] ? $composerLockFiles : $composerManifestFiles;

        $npmLockFiles = $this->collectManifestFiles($executionPath, ['package-lock.json', 'npm-shrinkwrap.json']);
        $npmManifestFiles = $this->collectManifestFiles($executionPath, ['package.json']);
        $npmFiles = $npmLockFiles !== [] ? $npmLockFiles : $npmManifestFiles;

        if ($composerFiles === [] && $npmFiles === []) {
            return $plan;
        }

        $output[] = 'FTP-only pipeline: comparing dependency lockfiles/manifests on the remote host.';
        if ($composerFiles !== []) {
            if ($composerLockFiles !== []) {
                $output[] = 'Composer: using lockfile comparison ('.implode(', ', $composerLockFiles).').';
            } else {
                $output[] = 'Composer: no lockfile found; using composer.json.';
            }
        }
        if ($npmFiles !== []) {
            if ($npmLockFiles !== []) {
                $output[] = 'Npm: using lockfile comparison ('.implode(', ', $npmLockFiles).').';
            } else {
                $output[] = 'Npm: no lockfile found; using package.json.';
            }
        }
        $remoteFiles = app(FtpService::class)->fetchRemoteFiles($project, array_merge($composerFiles, $npmFiles), $output);

        $composerChanged = $this->manifestSetChanged('Composer', $composerFiles, $remoteFiles, $executionPath, $output);
        $npmChanged = $this->manifestSetChanged('Npm', $npmFiles, $remoteFiles, $executionPath, $output);

        if ($project->run_composer_install) {
            if (! $composerChanged) {
                $vendorPath = $executionPath.DIRECTORY_SEPARATOR.'vendor';
                if (is_dir($vendorPath)) {
                    $plan['skipComposerInstall'] = true;
                    $plan['excludePaths'][] = 'vendor';
                } else {
                    $output[] = 'FTP-only pipeline: composer manifests unchanged, but vendor directory is missing locally.';
                }
            } else {
                $output[] = 'FTP-only pipeline: composer manifests changed; vendor will be synced.';
            }
        } elseif ($composerChanged) {
            $output[] = 'FTP-only pipeline: composer manifests changed, but composer install is disabled.';
        }

        if ($project->run_npm_install) {
            if (! $npmChanged) {
                $modulesPath = $executionPath.DIRECTORY_SEPARATOR.'node_modules';
                if (is_dir($modulesPath)) {
                    $plan['skipNpmInstall'] = true;
                    $plan['excludePaths'][] = 'node_modules';
                } else {
                    $output[] = 'FTP-only pipeline: npm manifests unchanged, but node_modules directory is missing locally.';
                }
            } else {
                $output[] = 'FTP-only pipeline: npm manifests changed; node_modules will be synced.';
            }
        } elseif ($npmChanged) {
            $output[] = 'FTP-only pipeline: npm manifests changed, but npm install is disabled.';
        }

        return $plan;
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function collectManifestFiles(string $executionPath, array $candidates): array
    {
        $found = [];
        foreach ($candidates as $file) {
            $path = $executionPath.DIRECTORY_SEPARATOR.$file;
            if (is_file($path)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * @param array<int, string> $files
     * @param array<string, string|null> $remoteFiles
     */
    private function manifestSetChanged(string $label, array $files, array $remoteFiles, string $executionPath, array &$output): bool
    {
        if ($files === []) {
            return false;
        }

        $changed = false;
        foreach ($files as $file) {
            $localPath = $executionPath.DIRECTORY_SEPARATOR.$file;
            if (! is_file($localPath)) {
                continue;
            }

            $localHash = hash_file('sha256', $localPath);
            $remoteContents = $remoteFiles[$file] ?? null;
            if ($remoteContents === null) {
                $output[] = $label.': remote '.$file.' missing or unreadable.';
                $changed = true;
                continue;
            }

            $remoteHash = hash('sha256', $remoteContents);
            if ($remoteHash !== $localHash) {
                $output[] = $label.': '.$file.' differs from remote.';
                $changed = true;
            }
        }

        if (! $changed) {
            $output[] = $label.': dependency files match remote.';
        }

        return $changed;
    }

    private function maybeRunSshCommands(Project $project, array &$output): void
    {
        if (! $project->ssh_enabled) {
            return;
        }

        $project->loadMissing('ftpAccount');
        if (! $project->ftpAccount) {
            $output[] = 'SSH skipped: no FTP/SSH access record configured for this project.';
            return;
        }

        $commands = $this->parseSshCommands($project->ssh_commands);
        if ($commands === []) {
            $output[] = 'SSH: no commands configured.';
            return;
        }

        $host = $project->ftpAccount->host;
        $username = $project->ftpAccount->username;
        $password = $project->ftpAccount->getDecryptedPassword();
        $port = (int) ($project->ssh_port ?: 22);
        $root = $project->ssh_root_path
            ?: ($project->ftp_root_path ?: $project->ftpAccount->root_path);

        $passBinary = trim((string) ($project->ftpAccount->ssh_pass_binary ?? ''));
        $keyPath = trim((string) ($project->ftpAccount->ssh_key_path ?? ''));

        $this->runSshCommands([
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password !== '' ? $password : null,
            'root' => $root ?: null,
            'pass_binary' => $passBinary !== '' ? $passBinary : null,
            'key_path' => $keyPath !== '' ? $keyPath : null,
        ], $commands, $output);
    }

    /**
     * @return array<int, string>
     */
    private function parseSshCommands(?string $commands): array
    {
        if (! $commands) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $commands) ?: [];
        $lines = array_map('trim', $lines);
        return array_values(array_filter($lines, fn (string $line) => $line !== ''));
    }

    private function ensureRemoteRepo(Project $project, array $connection, array &$output): void
    {
        if (! empty($connection['root'])) {
            $rootless = $this->sshConnectionWithoutRoot($connection);
            $this->runSshCommand($rootless, 'mkdir -p '.escapeshellarg($connection['root']), $output);
        }

        $exists = $this->runSshCommand($connection, 'if [ -d .git ]; then echo 1; fi', $output);
        if (in_array('1', $exists, true)) {
            return;
        }

        $repoUrl = trim((string) $project->repo_url);
        if ($repoUrl === '') {
            throw new \RuntimeException('Repository URL is required to initialize git for SSH deployment.');
        }

        $repoUrl = $this->normalizeRepoUrl($repoUrl);
        $branch = $project->default_branch ?: 'main';
        $repoArg = escapeshellarg($repoUrl);
        $branchArg = escapeshellarg($branch);
        $originArg = escapeshellarg('origin/'.$branch);

        $output[] = 'Remote repository not found. Initializing from '.$repoUrl.'.';
        $this->maybeStreamOutput($output, true);

        $command = 'was_empty=0; if [ -z "$(ls -A 2>/dev/null)" ]; then was_empty=1; fi; '
            .'GIT_TERMINAL_PROMPT=0 git init; '
            .'GIT_TERMINAL_PROMPT=0 git remote add origin '.$repoArg.'; '
            .'GIT_TERMINAL_PROMPT=0 git fetch --all --prune; '
            .'GIT_TERMINAL_PROMPT=0 git checkout -B '.$branchArg.'; '
            .'if [ "$was_empty" -eq 1 ]; then '
            .'GIT_TERMINAL_PROMPT=0 git reset --hard '.$originArg.'; '
            .'else GIT_TERMINAL_PROMPT=0 git reset --mixed '.$originArg.'; fi';

        $this->runSshCommand($connection, $command, $output);
    }

    private function sshConnectionWithoutRoot(array $connection): array
    {
        $connection['root'] = null;

        return $connection;
    }

    private function normalizeRepoUrl(string $repoUrl): string
    {
        $repoUrl = trim($repoUrl);
        if ($repoUrl === '') {
            throw new \RuntimeException('Repository URL is required to initialize git for this project.');
        }

        $host = null;
        $path = null;
        $scheme = null;
        $isSsh = false;

        if (str_starts_with($repoUrl, 'git@')) {
            $isSsh = true;
            if (preg_match('/^git@([^:]+):(.+)$/', $repoUrl, $matches)) {
                $host = $matches[1] ?? null;
                $path = $matches[2] ?? null;
            }
        } elseif (str_contains($repoUrl, '://')) {
            $parts = parse_url($repoUrl);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? null;
            $path = $parts['path'] ?? null;
        } else {
            if (substr_count($repoUrl, '/') === 1) {
                return 'https://github.com/'.$repoUrl.(str_ends_with($repoUrl, '.git') ? '' : '.git');
            }

            return $repoUrl;
        }

        if (! $host || ! $path) {
            return $repoUrl;
        }

        $path = trim($path, '/');
        $path = preg_replace('/\\.git$/', '', $path);
        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
        if (count($segments) < 2) {
            return $repoUrl;
        }

        $owner = $segments[0];
        $repo = $segments[1];

        if ($isSsh) {
            return 'git@'.$host.':'.$owner.'/'.$repo.'.git';
        }

        $scheme = $scheme ?: 'https';
        return $scheme.'://'.$host.'/'.$owner.'/'.$repo.'.git';
    }

    /**
     * @return array{host: string, port: int, username: string, password: ?string, root: ?string}
     */
    private function resolveSshConnection(Project $project): array
    {
        $project->loadMissing('ftpAccount');
        if (! $project->ftpAccount) {
            throw new \RuntimeException('SSH deployment requires an FTP/SSH access record.');
        }

        $host = trim((string) $project->ftpAccount->host);
        $username = trim((string) $project->ftpAccount->username);

        if ($host === '' || $username === '') {
            throw new \RuntimeException('FTP/SSH access record is missing host or username for SSH deployment.');
        }

        $password = $project->ftpAccount->getDecryptedPassword();
        $password = $password !== '' ? $password : null;

        $port = (int) ($project->ssh_port ?: ($project->ftpAccount->ssh_port ?? 22));
        if ($port <= 0) {
            $port = 22;
        }

        $root = $project->ssh_root_path
            ?: ($project->ftp_root_path ?: $project->ftpAccount->root_path);
        $root = trim((string) $root);

        $passBinary = trim((string) ($project->ftpAccount->ssh_pass_binary ?? ''));
        $keyPath = trim((string) ($project->ftpAccount->ssh_key_path ?? ''));

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'root' => $root !== '' ? $root : null,
            'pass_binary' => $passBinary !== '' ? $passBinary : null,
            'key_path' => $keyPath !== '' ? $keyPath : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function runSshCommand(array $connection, string $command, array &$output): array
    {
        $lines = app(SshService::class)->runCommand(
            $connection['host'],
            $connection['port'],
            $connection['username'],
            $connection['password'],
            $connection['root'],
            $command,
            $output,
            $connection['pass_binary'] ?? null,
            $connection['key_path'] ?? null
        );
        $this->maybeStreamOutput($output, true);

        return $lines;
    }

    /**
     * @param array<int, string> $commands
     */
    private function runSshCommands(array $connection, array $commands, array &$output): void
    {
        app(SshService::class)->runCommands(
            $connection['host'],
            $connection['port'],
            $connection['username'],
            $connection['password'],
            $connection['root'],
            $commands,
            $output,
            $connection['pass_binary'] ?? null,
            $connection['key_path'] ?? null
        );
        $this->maybeStreamOutput($output, true);
    }

    private function sshTryRevParse(array $connection, string $ref, array &$output): ?string
    {
        try {
            return $this->sshRevParse($connection, $ref, $output);
        } catch (\Throwable $exception) {
            $output[] = 'Warning: unable to resolve git reference '.$ref.' over SSH.';
            $this->maybeStreamOutput($output, true);
            return null;
        }
    }

    private function sshRevParse(array $connection, string $ref, array &$output): ?string
    {
        $lines = $this->runSshCommand($connection, 'git rev-parse --verify '.escapeshellarg($ref), $output);
        $hash = $this->parseSshHash($lines);
        if (! $hash) {
            throw new \RuntimeException('Unable to resolve git reference '.$ref.' over SSH.');
        }

        return $hash;
    }

    /**
     * @param array<int, string> $lines
     */
    private function parseSshHash(array $lines): ?string
    {
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private function remoteGitCleanCommand(Project $project, bool $dryRun): string
    {
        $command = 'git clean '.($dryRun ? '-fdn' : '-fd');
        $excludePaths = array_merge(['storage', '.htaccess', 'public/.htaccess'], $this->parseExcludePaths($project));

        foreach (array_unique($excludePaths) as $path) {
            $path = trim($path);
            if ($path === '' || $path === '.' || $path === '..') {
                continue;
            }

            $path = ltrim($path, '/\\');
            if ($path === '') {
                continue;
            }

            $command .= ' -e '.escapeshellarg($path);
        }

        return $command;
    }

    private function remoteNpmInstallCommand(): string
    {
        return 'if [ -f package-lock.json ]; then npm ci; else npm install; fi';
    }

    private function maybeRunLaravelClearCacheOverSsh(Project $project, array $connection, array &$output): void
    {
        if (($project->project_type ?? '') !== 'laravel') {
            return;
        }

        try {
            $this->runSshCommand(
                $connection,
                'if [ -f artisan ]; then php artisan config:clear; php artisan app:clear-cache; fi',
                $output
            );
        } catch (\Throwable $exception) {
            $output[] = 'Warning: app:clear-cache over SSH failed: '.$exception->getMessage();
            $this->maybeStreamOutput($output, true);
        }
    }

    private function chmodRecursivePath(string $path, int $dirMode, int $fileMode, array &$output): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $target = $item->getPathname();
            if ($item->isDir()) {
                @chmod($target, $dirMode);
            } else {
                @chmod($target, $fileMode);
            }
        }

        @chmod($path, $dirMode);
    }

    private function resolveHealthUrl(Project $project): ?string
    {
        $healthUrl = trim((string) $project->health_url);

        if ($healthUrl !== '') {
            if (str_starts_with($healthUrl, '/')) {
                $laravelRoot = $this->findLaravelRoot($project->local_path);
                $appUrl = $laravelRoot ? $this->getLaravelAppUrl($laravelRoot) : null;
                if ($appUrl) {
                    return rtrim($appUrl, '/').$healthUrl;
                }

                return null;
            }

            return $healthUrl;
        }

        $laravelRoot = $this->findLaravelRoot($project->local_path);
        if ($laravelRoot) {
            $appUrl = $this->getLaravelAppUrl($laravelRoot);
            if ($appUrl) {
                return rtrim($appUrl, '/').'/up';
            }
        }

        return null;
    }

    private function findLaravelRoot(string $path): ?string
    {
        return $this->walkUpForMarker($path, 'artisan');
    }

    private function walkUpForMarker(string $startPath, string $marker): ?string
    {
        $path = $startPath;

        while (true) {
            $candidate = $path.DIRECTORY_SEPARATOR.$marker;
            if (is_dir($candidate) || is_file($candidate)) {
                return $path;
            }

            $parent = dirname($path);
            if (! $parent || $parent === $path) {
                break;
            }

            $path = $parent;
        }

        return null;
    }

    private function isLaravelProject(string $path): bool
    {
        return $this->findLaravelRoot($path) !== null;
    }

    private function getLaravelAppUrl(string $path): ?string
    {
        return $this->getLaravelEnvValue($path, 'APP_URL');
    }

    private function getManagerEnvValue(string $key): ?string
    {
        return $this->getLaravelEnvValue(base_path(), $key);
    }

    private function getLaravelEnvValue(string $path, string $key): ?string
    {
        $envPath = $path.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envPath)) {
            return null;
        }

        $prefix = $key.'=';
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_starts_with($line, $prefix)) {
                continue;
            }

            $value = trim(substr($line, strlen($prefix)));
            $value = trim($value, "\"'");
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function clearLaravelConfigCache(string $laravelRoot, array &$output): void
    {
        $configCache = $laravelRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        if (! is_file($configCache)) {
            return;
        }

        if (@unlink($configCache)) {
            $output[] = 'Cleared Laravel config cache file before app:clear-cache.';
            return;
        }

        $output[] = 'Warning: unable to remove config cache file: '.$configCache;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\|^[A-Za-z]:\\//', $path);
    }
}
