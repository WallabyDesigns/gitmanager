<?php

namespace App\Services\Concerns;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait ManagesDependencies
{
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
                        $this->runBuildCommandWithNpmRecovery($project, $executionPath, $output);
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
                $this->resolveDependencyAuditIssuesForAction($project, 'dependency_update', $output);
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

    public function composerInstall(Project $project, ?User $user = null, ?bool $syncFtp = null): Deployment
    {
        $syncFtp = $syncFtp ?? $this->shouldUseFtpWorkspace($project);

        return $this->runMaintenanceAction($project, $user, 'composer_install', function (string $path, array &$output): void {
            $this->runComposerCommandWithFallback(
                $path,
                $output,
                'Composer install',
                ['composer', 'install', '--no-dev', '--optimize-autoloader']
            );
        }, true, $syncFtp);
    }

    public function composerUpdate(Project $project, ?User $user = null, ?bool $syncFtp = null): Deployment
    {
        $syncFtp = $syncFtp ?? $this->shouldUseFtpWorkspace($project);

        return $this->runMaintenanceAction($project, $user, 'composer_update', function (string $path, array &$output): void {
            $this->runComposerCommandWithFallback(
                $path,
                $output,
                'Composer update',
                ['composer', 'update']
            );
        }, true, $syncFtp);
    }

    public function composerAudit(Project $project, ?User $user = null): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);
        $useFtpManifests = $this->shouldUseFtpWorkspace($project);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'composer_audit',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];

        try {
            if ($useFtpManifests) {
                if (! $this->refreshFtpManifestFiles($project, $executionPath, ['composer.json', 'composer.lock'], $output, true)) {
                    throw new \RuntimeException('Unable to refresh FTP Composer files before audit.');
                }
            }

            $command = ['composer', 'audit'];
            if ($useFtpManifests && is_file($executionPath.DIRECTORY_SEPARATOR.'composer.lock')) {
                $command[] = '--locked';
            }

            $process = $this->runProjectProcess($command, $output, $executionPath, false);
            $exitCode = $process->getExitCode() ?? 1;
            $analysis = $this->analyzeComposerAuditOutput($output);

            if ($exitCode === 0) {
                $status = 'success';
            } elseif ($analysis['advisory_count'] !== null) {
                $status = 'warning';
                $count = $analysis['advisory_count'];
                $output[] = $count === 1
                    ? 'Composer audit found 1 security advisory.'
                    : "Composer audit found {$count} security advisories.";
            } elseif ($analysis['no_advisories']) {
                $status = 'success';
                $output[] = 'Composer audit exited with a warning code, but no advisories were reported.';
            } else {
                throw new ProcessFailedException($process);
            }

            $this->maybeRunLaravelClearCache($project, $output);

            $deployment->status = $status;
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();

            if ($status !== 'failed') {
                $project->last_error_message = null;
                $project->save();
            }

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

    public function laravelMigrate(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'laravel_migrate', function (string $path, array &$output) use ($project): void {
            $laravelRoot = $this->findLaravelRoot($project->local_path)
                ?? $this->findLaravelRoot($path);

            if (! $laravelRoot) {
                throw new \RuntimeException('Laravel app not found for this project.');
            }

            if (! $this->artisanCommandExists($laravelRoot, 'migrate', $output)) {
                throw new \RuntimeException('Command migrate not found.');
            }

            if (! $this->laravelDatabaseIsAvailable($laravelRoot, $output, 'migrate')) {
                throw new \RuntimeException('Database configuration missing; update .env before running migrations.');
            }

            $this->runProjectProcess(['php', 'artisan', 'migrate', '--force'], $output, $laravelRoot);
        });
    }

    public function npmInstall(Project $project, ?User $user = null, ?bool $syncFtp = null): Deployment
    {
        $syncFtp = $syncFtp ?? $this->shouldUseFtpWorkspace($project);

        return $this->runMaintenanceAction($project, $user, 'npm_install', function (string $path, array &$output): void {
            $this->runNpmInstallWithFallback(
                $path,
                $output,
                'Npm install',
                ['npm', 'install']
            );
        }, false, $syncFtp);
    }

    public function npmUpdate(Project $project, ?User $user = null, ?bool $syncFtp = null): Deployment
    {
        $syncFtp = $syncFtp ?? $this->shouldUseFtpWorkspace($project);

        return $this->runMaintenanceAction($project, $user, 'npm_update', function (string $path, array &$output): void {
            $this->runNpmCommandWithFallback(
                $path,
                $output,
                'Npm update',
                ['npm', 'update'],
                true
            );
        }, false, $syncFtp);
    }

    public function npmAuditFix(Project $project, ?User $user = null, bool $force = false): Deployment
    {
        $syncFtp = false;

        return $this->runMaintenanceAction($project, $user, $force ? 'npm_audit_fix_force' : 'npm_audit_fix', function (string $path, array &$output) use ($force, $project): void {
            if ($this->shouldUseFtpWorkspace($project)) {
                if (! $this->refreshFtpManifestFiles($project, $path, [
                    'package.json',
                    'package-lock.json',
                    'npm-shrinkwrap.json',
                    'pnpm-lock.yaml',
                    'yarn.lock',
                ], $output, true)) {
                    throw new \RuntimeException('Unable to refresh FTP npm files before audit.');
                }
            }

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

            $this->syncFtpManifestFiles($project, $path, [
                'package.json',
                'package-lock.json',
                'npm-shrinkwrap.json',
                'pnpm-lock.yaml',
                'yarn.lock',
            ], $output);
        }, false, $syncFtp);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function auditDependencies(Project $project, ?User $user = null, bool $autoFix = true): array
    {
        $results = [];

        $this->refreshFtpAuditManifestFiles($project);

        if ($this->hasComposer($project)) {
            $results['composer'] = $this->runComposerAuditFlow($project, $user, $autoFix);
        }

        if ($this->hasNpm($project)) {
            $results['npm'] = $this->runNpmAuditFlow($project, $user, $autoFix);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function runComposerAuditFlow(Project $project, ?User $user, bool $autoFix): array
    {
        $deploymentIds = [];
        $initial = $this->composerAudit($project, $user);
        $deploymentIds[] = $initial->id;

        $analysis = $this->parseComposerAuditLog($initial->output_log);
        $found = $analysis['advisory_count'];
        if ($analysis['no_advisories'] && $found === null) {
            $found = 0;
        }

        $remaining = $found;
        $fixed = null;
        $fixApplied = false;
        $fixSummary = null;
        $status = $initial->status;

        if ($status === 'failed') {
            return [
                'tool' => 'composer',
                'status' => $status,
                'found' => null,
                'fixed' => null,
                'remaining' => null,
                'summary' => 'Composer audit failed.',
                'fix_summary' => null,
                'fix_applied' => false,
                'deployment_ids' => $deploymentIds,
            ];
        }

        if ($status !== 'failed' && $autoFix && $found !== null && $found > 0) {
            $fixApplied = true;
            $update = $this->composerUpdate($project, $user, $this->shouldUseFtpWorkspace($project));
            $deploymentIds[] = $update->id;

            $after = $this->composerAudit($project, $user);
            $deploymentIds[] = $after->id;
            $afterAnalysis = $this->parseComposerAuditLog($after->output_log);
            $status = $after->status;

            $remaining = $afterAnalysis['advisory_count'];
            if ($afterAnalysis['no_advisories'] && $remaining === null) {
                $remaining = 0;
            }

            if ($found !== null && $remaining !== null) {
                $fixed = max($found - $remaining, 0);
            }

            if ($remaining === 0 && $found !== null) {
                $fixSummary = $found === 1
                    ? 'Composer audit resolved 1 advisory.'
                    : "Composer audit resolved {$found} advisories.";
            }
        }

        $summary = $this->buildComposerSummary($status, $remaining);

        return [
            'tool' => 'composer',
            'status' => $status,
            'found' => $found,
            'fixed' => $fixed,
            'remaining' => $remaining,
            'summary' => $summary,
            'fix_summary' => $fixSummary,
            'fix_applied' => $fixApplied,
            'deployment_ids' => $deploymentIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runNpmAuditFlow(Project $project, ?User $user, bool $autoFix): array
    {
        $deploymentIds = [];
        $deployment = $this->npmAuditFix($project, $user, false);
        $deploymentIds[] = $deployment->id;

        if ($deployment->status === 'failed') {
            return [
                'tool' => 'npm',
                'status' => $deployment->status,
                'found' => null,
                'fixed' => null,
                'remaining' => null,
                'severity' => null,
                'summary' => 'Npm audit failed.',
                'fix_summary' => null,
                'fix_applied' => false,
                'deployment_ids' => $deploymentIds,
            ];
        }

        $analysis = $this->parseNpmAuditLog($deployment->output_log);
        $summary = $this->buildNpmSummary($deployment->status, $analysis);

        $fixSummary = null;
        if (($analysis['remaining'] ?? null) === 0 && ($analysis['fixed'] ?? 0) > 0) {
            $fixed = (int) $analysis['fixed'];
            $fixSummary = $fixed === 1
                ? 'Npm audit fixed 1 vulnerability.'
                : "Npm audit fixed {$fixed} vulnerabilities.";
        }

        return [
            'tool' => 'npm',
            'status' => $deployment->status,
            'found' => $analysis['found'],
            'fixed' => $analysis['fixed'],
            'remaining' => $analysis['remaining'],
            'severity' => $analysis['severity_summary'],
            'summary' => $summary,
            'fix_summary' => $fixSummary,
            'fix_applied' => true,
            'deployment_ids' => $deploymentIds,
        ];
    }

    /**
     * @return array{no_advisories: bool, advisory_count: int|null}
     */
    private function parseComposerAuditLog(?string $log): array
    {
        $lines = preg_split('/\r?\n/', (string) $log) ?: [];

        return $this->analyzeComposerAuditOutput($lines);
    }

    private function buildComposerSummary(string $status, ?int $remaining): string
    {
        if ($status === 'failed') {
            return 'Composer audit failed.';
        }

        if ($remaining === 0) {
            return 'Composer audit found no remaining advisories.';
        }

        if ($remaining !== null) {
            return $remaining === 1
                ? 'Composer audit found 1 advisory.'
                : "Composer audit found {$remaining} advisories.";
        }

        return 'Composer audit completed.';
    }

    /**
     * @return array{found: int|null, fixed: int|null, total: int|null, remaining: int|null, severity_summary: string|null}
     */
    private function parseNpmAuditLog(?string $log): array
    {
        $text = trim((string) $log);
        if ($text === '') {
            return [
                'found' => null,
                'fixed' => null,
                'total' => null,
                'remaining' => null,
                'severity_summary' => null,
            ];
        }

        $lines = array_reverse(preg_split('/\r?\n/', $text) ?: []);
        $found = null;
        $severitySummary = null;
        $fixed = null;
        $total = null;
        $remaining = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($found === null && preg_match('/found\s+(\d+)\s+vulnerabilities(?:\s+\(([^)]+)\))?/i', $line, $matches)) {
                $found = (int) $matches[1];
                $severitySummary = isset($matches[2]) ? trim($matches[2]) : null;
            }

            if ($fixed === null && preg_match('/fixed\s+(\d+)\s+of\s+(\d+)\s+vulnerabilities/i', $line, $matches)) {
                $fixed = (int) $matches[1];
                $total = (int) $matches[2];
            }

            if ($found !== null && $fixed !== null) {
                break;
            }
        }

        if ($fixed !== null && $total !== null) {
            $remaining = max($total - $fixed, 0);
            if ($found === null) {
                $found = $total;
            }
        } elseif ($found !== null) {
            $remaining = $found;
        }

        return [
            'found' => $found ?? $total,
            'fixed' => $fixed,
            'total' => $total,
            'remaining' => $remaining,
            'severity_summary' => $severitySummary,
        ];
    }

    /**
     * @param array{found: int|null, fixed: int|null, total: int|null, remaining: int|null, severity_summary: string|null} $analysis
     */
    private function buildNpmSummary(string $status, array $analysis): string
    {
        if ($status === 'failed') {
            return 'Npm audit failed.';
        }

        if (($analysis['remaining'] ?? null) === 0) {
            return 'Npm audit found no remaining vulnerabilities.';
        }

        if ($analysis['remaining'] !== null) {
            $remaining = (int) $analysis['remaining'];

            return $remaining === 1
                ? 'Npm audit found 1 vulnerability.'
                : "Npm audit found {$remaining} vulnerabilities.";
        }

        return 'Npm audit completed.';
    }

    /**
     * @param array<int, string> $files
     */
    private function refreshFtpManifestFiles(Project $project, string $executionPath, array $files, array &$output, bool $deleteMissing = false): bool
    {
        if (! $this->shouldUseFtpWorkspace($project)) {
            return true;
        }

        $remoteFiles = app(\App\Services\FtpService::class)->fetchRemoteFiles($project, $files, $output);
        if ($remoteFiles === []) {
            return false;
        }

        foreach ($files as $file) {
            $contents = $remoteFiles[$file] ?? null;
            if ($contents === null) {
                if ($deleteMissing) {
                    $target = $executionPath.DIRECTORY_SEPARATOR.$file;
                    if (is_file($target) && @unlink($target)) {
                        $output[] = 'FTP manifest sync: removed stale '.$file.'.';
                    }
                }
                continue;
            }

            $target = $executionPath.DIRECTORY_SEPARATOR.$file;
            $current = is_file($target) ? @file_get_contents($target) : null;
            if ($current === $contents) {
                continue;
            }

            $dir = dirname($target);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            if (@file_put_contents($target, $contents) !== false) {
                $output[] = 'FTP manifest sync: downloaded '.$file.'.';
            }
        }

        return true;
    }

    private function refreshFtpAuditManifestFiles(Project $project): void
    {
        if (! $this->shouldUseFtpWorkspace($project)) {
            return;
        }

        try {
            $repoPath = $this->resolveRepoPath($project);
            $executionPath = $this->resolveExecutionPath($project, $repoPath);
            $output = [];

            $this->refreshFtpManifestFiles($project, $executionPath, [
                'composer.json',
                'composer.lock',
                'package.json',
                'package-lock.json',
                'npm-shrinkwrap.json',
                'pnpm-lock.yaml',
                'yarn.lock',
            ], $output, true);
        } catch (\Throwable $exception) {
            // Individual audit actions will surface connection or filesystem errors.
        }
    }

    public function fixPermissions(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'fix_permissions', function (string $path, array &$output) use ($project): void {
            $this->permissionService->attemptFixPermissions($project, $path, $output, true);
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

    private function runComposerCommandWithFallback(string $path, array &$output, string $label, array $command, bool $forceStaged = false): void
    {
        $vendorPath = $path.DIRECTORY_SEPARATOR.'vendor';
        $projectWritable = is_writable($path);
        $vendorWritable = is_dir($vendorPath) ? is_writable($vendorPath) : $projectWritable;
        $phpBinary = $this->phpBinary();
        if ($phpBinary !== '') {
            $output[] = 'Composer will use PHP binary: '.$phpBinary;
        }

        $this->permissionService->ensureWritableDirectory($path, $output, 'Project directory');
        if ($forceStaged) {
            $output[] = 'Running composer command using staged install.';
        } elseif ($vendorWritable) {
            $this->permissionService->ensureWritableDirectory($vendorPath, $output, 'Vendor directory');
            try {
                $this->logStep($output, $label, $path, implode(' ', $command));
                $this->runProjectProcess($command, $output, $path);

                return;
            } catch (ProcessFailedException $exception) {
                if (! $this->permissionService->isPermissionError($exception, $output)) {
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
            $this->permissionService->applyOwnershipFromReference($vendorPath, $path, $output, 'Vendor directory');
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

        $this->permissionService->ensureWritableDirectory($path, $output, 'Project directory');
        $useStagedInstall = $forceStaged || ($commandVerb === 'ci' && is_dir($modulesPath));
        if ($useStagedInstall) {
            $output[] = $forceStaged
                ? 'Running npm command using staged install.'
                : 'Node modules directory exists. Running npm ci in staged mode to avoid permission conflicts.';
        }

        if ($modulesWritable && ! $useStagedInstall) {
            $this->permissionService->ensureWritableDirectory($modulesPath, $output, 'Node modules directory');
            try {
                $this->logStep($output, $label, $path, implode(' ', $command));
                $this->runProjectProcess($command, $output, $path);

                return;
            } catch (ProcessFailedException $exception) {
                if (! $this->permissionService->isPermissionError($exception, $output)) {
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
            $this->permissionService->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
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

    private function attemptNpmCleanReinstall(string $path, array &$output, string $label, array $command, string $managerLabel = 'Npm'): void
    {
        $modulesPath = $path.DIRECTORY_SEPARATOR.'node_modules';

        $managerLabel = trim($managerLabel) !== '' ? $managerLabel : 'Npm';

        if (! is_dir($modulesPath)) {
            $output[] = 'Starting '.$managerLabel.' clean reinstall (no existing node_modules).';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            $this->permissionService->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
            $output[] = $managerLabel.' clean reinstall completed.';

            return;
        }

        $backup = $this->backupDirectory($modulesPath, $output, 'Node modules directory');
        if (! $backup) {
            throw new \RuntimeException('Unable to backup node_modules for clean reinstall.');
        }

        try {
            $output[] = 'Starting '.$managerLabel.' clean reinstall.';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
            $output[] = $managerLabel.' clean reinstall completed.';
        } catch (\Throwable $exception) {
            if (is_dir($modulesPath)) {
                $this->deleteDirectory($modulesPath);
            }

            $this->restoreDirectoryBackup($backup, $modulesPath, $output, 'Node modules directory');
            $output[] = $managerLabel.' clean reinstall failed; restored node_modules backup.';
            throw $exception;
        }

        $this->permissionService->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
    }

    private function attemptComposerCleanReinstall(string $path, array &$output, string $label, array $command): void
    {
        $vendorPath = $path.DIRECTORY_SEPARATOR.'vendor';

        if (! is_dir($vendorPath)) {
            $output[] = 'Starting composer clean reinstall (no existing vendor directory).';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            $this->permissionService->applyOwnershipFromReference($vendorPath, $path, $output, 'Vendor directory');
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

        $this->permissionService->applyOwnershipFromReference($vendorPath, $path, $output, 'Vendor directory');
    }

    private function shouldAttemptComposerCleanReinstall(\Throwable $exception, array $output): bool
    {
        if ($this->permissionService->isPermissionError($exception, $output)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unable to move existing vendor directory')
            || str_contains($message, 'unable to replace vendor directory');
    }

    private function shouldAttemptNpmCleanReinstall(\Throwable $exception, array $output): bool
    {
        if ($this->permissionService->isPermissionError($exception, $output)) {
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

    private function runBuildCommandWithNpmRecovery(Project $project, string $path, array &$output, string $label = 'Build command'): void
    {
        $command = trim((string) $project->build_command);
        if ($command === '') {
            return;
        }

        $this->ensureBuildOutputWritable($path, $output);
        $this->logStep($output, $label, $path, $command);

        try {
            $this->runProjectShellCommand($command, $output, $path);
        } catch (\Throwable $exception) {
            $manager = $this->detectBuildPackageManager($command);
            if (! $manager) {
                throw $exception;
            }

            if (! is_file($path.DIRECTORY_SEPARATOR.'package.json')) {
                throw $exception;
            }

            $labelPrefix = strtoupper($manager);
            $output[] = $labelPrefix.' build failed. Removing node_modules and reinstalling dependencies.';
            $installCommand = $this->installCommandForPackageManager($manager, $path);
            $this->attemptNpmCleanReinstall($path, $output, $labelPrefix.' install', $installCommand, $labelPrefix);
            $this->logStep($output, $label.' (retry)', $path, $command);
            $this->runProjectShellCommand($command, $output, $path);
        }
    }

    private function detectBuildPackageManager(string $command): ?string
    {
        $normalized = strtolower(trim($command));
        if ($normalized === '') {
            return null;
        }

        $patterns = [
            'npm' => '/\bnpm\b.*\bbuild\b/',
            'yarn' => '/\byarn\b.*\bbuild\b/',
            'pnpm' => '/\bpnpm\b.*\bbuild\b/',
        ];

        foreach ($patterns as $manager => $pattern) {
            if (preg_match($pattern, $normalized)) {
                return $manager;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function installCommandForPackageManager(string $manager, string $path): array
    {
        return match ($manager) {
            'yarn' => ['yarn', 'install'],
            'pnpm' => ['pnpm', 'install'],
            default => $this->npmInstallCommand($path),
        };
    }

    private function ensureBuildOutputWritable(string $rootPath, array &$output): void
    {
        $buildPath = $rootPath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';
        $assetsPath = $buildPath.DIRECTORY_SEPARATOR.'assets';

        $this->permissionService->ensureWritableDirectory($buildPath, $output, 'Build directory');
        $this->permissionService->ensureWritableDirectory($assetsPath, $output, 'Build assets directory');
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

        if (! $this->laravelDatabaseIsAvailable($laravelRoot, $output, 'app:clear-cache')) {
            return;
        }

        try {
            $this->runProjectProcess(['php', 'artisan', 'app:clear-cache'], $output, $laravelRoot);
        } catch (\Throwable $exception) {
            $output[] = 'Warning: app:clear-cache failed: '.$exception->getMessage();
        }
    }

    private function maybeRunLaravelMigrations(Project $project, string $executionPath, array &$output): void
    {
        $laravelRoot = $this->findLaravelRoot($executionPath)
            ?? $this->findLaravelRoot($project->local_path);
        if (! $laravelRoot) {
            return;
        }

        if (! $this->artisanCommandExists($laravelRoot, 'migrate', $output)) {
            return;
        }

        if (! $this->laravelDatabaseIsAvailable($laravelRoot, $output, 'migrate')) {
            return;
        }

        $this->logStep($output, 'Laravel migrate', $laravelRoot, 'php artisan migrate --force');
        try {
            $this->runProjectProcess(['php', 'artisan', 'migrate', '--force'], $output, $laravelRoot);
        } catch (ProcessFailedException $exception) {
            if ($project->ignore_migration_table_exists && $this->isMigrationAlreadyAppliedError($exception)) {
                $output[] = 'Warning: migration failed because tables already exist. Skipping migrations.';
                return;
            }

            throw $exception;
        }
    }

    private function isMigrationAlreadyAppliedError(\Throwable $exception): bool
    {
        $text = strtolower($exception->getMessage());

        if ($exception instanceof ProcessFailedException) {
            $process = $exception->getProcess();
            $text = strtolower($process->getOutput()."\n".$process->getErrorOutput()."\n".$exception->getMessage());
        }

        if (str_contains($text, 'sqlstate[42s01]')) {
            return true;
        }

        if (str_contains($text, 'base table or view already exists')) {
            return true;
        }

        if (str_contains($text, 'errno: 1050')) {
            return true;
        }

        return str_contains($text, 'table') && str_contains($text, 'already exists');
    }

    private function laravelDatabaseIsAvailable(string $laravelRoot, array &$output, string $label): bool
    {
        $envPath = $laravelRoot.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envPath)) {
            $output[] = 'Skipping '.$label.': .env file not found at '.$envPath.'.';

            return false;
        }

        $connection = $this->getLaravelEnvValue($laravelRoot, 'DB_CONNECTION');
        if ($connection === null || trim($connection) === '') {
            $output[] = 'Skipping '.$label.': DB_CONNECTION not set in project .env.';

            return false;
        }

        if (strtolower($connection) === 'sqlite') {
            $database = $this->getLaravelEnvValue($laravelRoot, 'DB_DATABASE');
            if ($database === null || trim($database) === '') {
                $database = 'database'.DIRECTORY_SEPARATOR.'database.sqlite';
                $output[] = 'DB_DATABASE not set; defaulting sqlite path to '.$database.'.';
            }

            $database = trim($database);
            if ($database !== ':memory:') {
                $resolved = $this->isAbsolutePath($database)
                    ? $database
                    : $laravelRoot.DIRECTORY_SEPARATOR.$database;

                if (! file_exists($resolved)) {
                    $directory = dirname($resolved);
                    $this->permissionService->ensureWritableDirectory($directory, $output, 'SQLite database directory');
                    if (is_dir($directory) && is_writable($directory) && @touch($resolved)) {
                        $output[] = 'Created sqlite database file at '.$resolved.'.';
                        return true;
                    }

                    $output[] = 'Skipping '.$label.': sqlite database file not found at '.$resolved;

                    return false;
                }
            }
        }

        return true;
    }

    private function artisanCommandExists(string $path, string $command, array &$output): bool
    {
        $probeOutput = [];
        $process = $this->runProjectProcess(['php', 'artisan', 'list', '--format=json'], $probeOutput, $path, false);

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ($error !== '') {
                $output[] = 'Unable to query artisan commands: '.$error;
            }

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

        return (bool) preg_match('/(^|\s)artisan\s+test(\s|$)/', $normalized);
    }

    private function logStep(array &$output, string $label, string $path, string $command): void
    {
        $output[] = $label.' (path: '.$path.')';
        $output[] = '$ '.$command;
        $this->maybeStreamOutput($output);
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

    /**
     * @param array<int, string> $output
     * @return array{no_advisories: bool, advisory_count: int|null}
     */
    private function analyzeComposerAuditOutput(array $output): array
    {
        $text = implode("\n", $output);
        $noAdvisories = (bool) preg_match('/no security vulnerability advisories found/i', $text);
        $count = null;

        if (preg_match('/found\\s+(\\d+)\\s+security vulnerability advisories?/i', $text, $matches)) {
            $count = (int) $matches[1];
        }

        return [
            'no_advisories' => $noAdvisories,
            'advisory_count' => $count,
        ];
    }

    /**
     * @param array<int, string> $files
     * @param array<int, string> $output
     */
    private function syncFtpManifestFiles(Project $project, string $path, array $files, array &$output): void
    {
        if (! $this->shouldUseFtpWorkspace($project)) {
            return;
        }

        $paths = [];
        foreach ($files as $file) {
            $candidate = $path.DIRECTORY_SEPARATOR.$file;
            if (is_file($candidate)) {
                $paths[] = $file;
            }
        }

        if ($paths === []) {
            return;
        }

        $output[] = 'FTP-only pipeline: syncing dependency files ('.implode(', ', $paths).').';
        app(\App\Services\FtpService::class)->syncFiles($project, $path, $paths, $output);
    }
}
