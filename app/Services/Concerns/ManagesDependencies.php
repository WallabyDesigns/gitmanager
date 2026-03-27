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

    private function attemptNpmCleanReinstall(string $path, array &$output, string $label, array $command): void
    {
        $modulesPath = $path.DIRECTORY_SEPARATOR.'node_modules';

        if (! is_dir($modulesPath)) {
            $output[] = 'Starting npm clean reinstall (no existing node_modules).';
            $this->logStep($output, $label.' (clean reinstall)', $path, implode(' ', $command));
            $this->runProjectProcess($command, $output, $path);
            $this->permissionService->applyOwnershipFromReference($modulesPath, $path, $output, 'Node modules directory');
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
        $this->runProjectProcess(['php', 'artisan', 'migrate', '--force'], $output, $laravelRoot);
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
                $output[] = 'Skipping '.$label.': sqlite database path not set.';

                return false;
            }

            $database = trim($database);
            if ($database !== ':memory:') {
                $resolved = $this->isAbsolutePath($database)
                    ? $database
                    : $laravelRoot.DIRECTORY_SEPARATOR.$database;

                if (! file_exists($resolved)) {
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
}
