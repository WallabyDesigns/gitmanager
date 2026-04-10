<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Concerns\ManagesDependencies;
use App\Services\Concerns\ManagesGitWorkingTree;
use App\Services\Concerns\ManagesPreviewBuilds;
use App\Services\Concerns\ManagesRemoteDeployments;
use App\Services\Concerns\RunsProcesses;

class DeploymentService
{
    use ManagesDependencies;
    use ManagesGitWorkingTree;
    use ManagesPreviewBuilds;
    use ManagesRemoteDeployments;
    use RunsProcesses;

    private ?Deployment $activeDeployment = null;
    private int $lastOutputCount = 0;
    private float $lastStreamAt = 0.0;
    private int $processCounter = 0;

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly PermissionService $permissionService,
        private readonly LaravelDeploymentCheckService $laravelDeploymentCheckService,
    ) {}

    public function checkHealth(Project $project, bool $log = false, bool $notifyOnFailure = false): string
    {
        return $this->healthCheckService->checkHealth($project, $log, $notifyOnFailure);
    }

    /**
     * @return array{status: string, message: string, parent?: string}
     */
    public function checkPathPermissions(string $path): array
    {
        return $this->permissionService->checkPathPermissions($path);
    }

    public function checkForUpdates(Project $project): bool
    {
        if (! $project->repo_url) {
            $project->last_checked_at = now();
            $project->updates_checked_at = now();
            $project->updates_available = false;
            $project->save();

            return false;
        }

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
        if ($project->permissions_locked && ! $project->ftp_enabled && ! $project->ssh_enabled && ! $ignorePermissionsLock) {
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
                    if ($this->shouldRunInitialDeployTasks($project)) {
                        $output[] = 'No updates detected. Running initial setup tasks.';
                        $this->resetToRemote($project, $repoPath, $project->default_branch, $output, $allowDirty);
                        $this->laravelDeploymentCheckService->run($project, $executionPath, $output);

                        $ftpPlan = $this->planFtpOnlyDependencySync($project, $executionPath, $output);

                        if (! $permissionsStep && ! $forceStaged && $this->permissionService->needsPermissionFix($project, $executionPath)) {
                            $output[] = 'Permission issues detected. Running Fix Permissions.';
                            $this->permissionService->attemptFixPermissions($project, $executionPath, $output, false);
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
                                $this->runBuildCommandWithNpmRecovery($project, $executionPath, $output);
                            }

                            if ($project->run_test_command && $project->test_command) {
                                $this->logStep($output, 'Test command', $executionPath, $project->test_command);
                                $this->runTestCommand($project, $project->test_command, $executionPath, $output);
                            }

                            $this->maybeRunLaravelMigrations($project, $executionPath, $output);
                            $this->maybeRunLaravelClearCache($project, $output);
                        }, $output, 'Initial deploy tasks', function (\Throwable $exception) use (&$output): bool {
                            return ! $this->permissionService->isPermissionError($exception, $output);
                        });

                        $this->maybeSyncFtp($project, $executionPath, $output, $ftpPlan['excludePaths'] ?? []);
                        $this->maybeRunSshCommands($project, $output);

                        if ($stashed) {
                            $this->restoreStashOrReset($repoPath, $output, $fromHash ?? 'HEAD');
                        }

                        $deployment->status = 'success';
                        $deployment->from_hash = $fromHash;
                        $deployment->to_hash = $fromHash;
                        $this->appendWorkflowOutput($deployment, $project, $output);
                        $deployment->output_log = implode("\n", $output);
                        $deployment->finished_at = now();
                        $deployment->save();

                        $project->last_deployed_at = now();
                        $project->last_deployed_hash = $fromHash;
                        $project->last_error_message = null;
                        $project->updates_available = false;
                        $project->updates_checked_at = now();
                        $project->last_checked_at = now();
                        $project->save();

                        $this->endDeploymentStream();

                        return $deployment;
                    }

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

                $this->laravelDeploymentCheckService->run($project, $executionPath, $output);

                $ftpPlan = $this->planFtpOnlyDependencySync($project, $executionPath, $output);

                if (! $permissionsStep && ! $forceStaged && $this->permissionService->needsPermissionFix($project, $executionPath)) {
                    $output[] = 'Permission issues detected. Running Fix Permissions.';
                    $this->permissionService->attemptFixPermissions($project, $executionPath, $output, false);
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
                        $this->runBuildCommandWithNpmRecovery($project, $executionPath, $output);
                    }

                    if ($project->run_test_command && $project->test_command) {
                        $this->logStep($output, 'Test command', $executionPath, $project->test_command);
                        $this->runTestCommand($project, $project->test_command, $executionPath, $output);
                    }

                    $this->maybeRunLaravelMigrations($project, $executionPath, $output);
                    $this->maybeRunLaravelClearCache($project, $output);
                }, $output, 'Post-deploy tasks', function (\Throwable $exception) use (&$output): bool {
                    return ! $this->permissionService->isPermissionError($exception, $output);
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

                if (! $permissionsStep && $this->permissionService->isPermissionError($exception, $output)) {
                    $output[] = 'Permission error detected. Running Fix Permissions.';
                    $this->permissionService->attemptFixPermissions($project, $executionPath, $output, false);
                    $permissionsStep = true;
                    $forceStaged = true;
                    $output[] = 'Retrying deployment using staged installs.';
                    continue;
                }

                if ($previousHealthOk && ! $autoRollbackAttempted) {
                    $autoRollbackAttempted = true;
                    $output[] = 'Deployment failed. Checking health status for rollback.';
                    $currentHealth = $this->healthCheckService->checkHealth($project);
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
        if ($project->permissions_locked && ! $project->ftp_enabled && ! $project->ssh_enabled) {
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
                        $this->runBuildCommandWithNpmRecovery($project, $executionPath, $output);
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

    /**
     * @return array{dirty: bool, files: array<int, string>}
     */
    public function getWorkingTreeChanges(Project $project): array
    {
        $repoPath = $this->resolveRepoPath($project);
        $output = [];
        $status = $this->getWorkingTreeStatus($repoPath, $output);

        return [
            'dirty' => $status !== '',
            'files' => $this->parseGitStatusFiles($status),
        ];
    }

    /**
     * @param array<int, string>|null $paths
     * @return array{status: string, branch?: string|null, output?: array<int, string>}
     */
    public function commitAndPush(Project $project, string $message, ?array $paths = null): array
    {
        $repoPath = $this->resolveRepoPath($project);
        $output = [];

        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return ['status' => 'clean'];
        }

        $branch = trim($this->runProcess([
            'git',
            '-C',
            $repoPath,
            'rev-parse',
            '--abbrev-ref',
            'HEAD',
        ], $output)->getOutput());

        if ($branch === '' || $branch === 'HEAD') {
            throw new \RuntimeException('Unable to determine the current branch for this project.');
        }

        $paths = $paths ? array_values(array_filter(array_map('trim', $paths), fn ($path) => $path !== '')) : [];
        if ($paths) {
            $this->runProcess(array_merge(['git', '-C', $repoPath, 'add', '--'], $paths), $output);
        } else {
            $this->runProcess(['git', '-C', $repoPath, 'add', '-A'], $output);
        }

        $commit = $this->runProcess([
            'git',
            '-C',
            $repoPath,
            'commit',
            '-m',
            $message,
        ], $output, null, false);

        if (! $commit->isSuccessful()) {
            return ['status' => 'no-commit', 'branch' => $branch, 'output' => $output];
        }

        $this->runProcess(['git', '-C', $repoPath, 'push', 'origin', $branch], $output);

        return ['status' => 'pushed', 'branch' => $branch, 'output' => $output];
    }

    /**
     * @return array<int, string>
     */
    private function parseGitStatusFiles(string $status): array
    {
        if ($status === '') {
            return [];
        }

        $files = [];
        foreach (preg_split('/\r?\n/', $status) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }

            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim(end($parts));
            }

            $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
            $files[] = $path;
        }

        return array_values(array_unique($files));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Streaming
    // ──────────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────────
    //  Maintenance action lifecycle
    // ──────────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────────
    //  Staging checks
    // ──────────────────────────────────────────────────────────────────

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
                $this->laravelDeploymentCheckService->run($project, $stagePath, $output, false);
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
                    $this->runBuildCommandWithNpmRecovery($project, $stagePath, $output, 'Staging build command');
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

    private function shouldRunInitialDeployTasks(Project $project): bool
    {
        if ($project->last_deployed_at || $project->last_deployed_hash) {
            return false;
        }

        return (bool) (
            $project->run_composer_install
            || $project->run_npm_install
            || $project->run_build_command
            || $project->run_test_command
            || ($project->project_type ?? '') === 'laravel'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Workflow integration
    // ──────────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────────
    //  Path resolution
    // ──────────────────────────────────────────────────────────────────

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

        $parent = $this->permissionService->closestExistingParent($path);

        return $parent !== null && is_writable($parent);
    }

    private function resolveExecutionPath(Project $project, string $repoPath): string
    {
        $laravelRoot = $this->findLaravelRoot($repoPath);
        if ($laravelRoot) {
            return $laravelRoot;
        }

        $ftpWorkspacePath = $this->ftpWorkspacePath($project);
        $usingFtpWorkspace = $this->shouldUseFtpWorkspace($project)
            && rtrim($repoPath, DIRECTORY_SEPARATOR) === rtrim($ftpWorkspacePath, DIRECTORY_SEPARATOR);

        if (! $usingFtpWorkspace) {
            $laravelRoot = $this->findLaravelRoot($project->local_path);
            if ($laravelRoot) {
                return $laravelRoot;
            }
        }

        return $repoPath;
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

    // ──────────────────────────────────────────────────────────────────
    //  File system utilities
    // ──────────────────────────────────────────────────────────────────

    private function ensurePath(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
            if ($this->permissionService->attemptPrivilegedPathCreate($path)) {
                return;
            }
            throw new \RuntimeException('Project path not found or not writable: '.$path);
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

    // ──────────────────────────────────────────────────────────────────
    //  Shared helpers
    // ──────────────────────────────────────────────────────────────────

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
