<?php

namespace App\Services\Concerns;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;

trait ManagesRemoteDeployments
{
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
                if ($this->shouldRunInitialDeployTasks($project)) {
                    $output[] = 'No updates detected. Running initial setup tasks over SSH.';
                    $this->maybeStreamOutput($output, true);
                    $this->runSshCommand(
                        $connection,
                        'git reset --hard '.escapeshellarg($remoteRef),
                        $output
                    );

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
                            $this->runSshBuildCommandWithNpmRecovery($project, $connection, $output);
                        }

                        if ($project->run_test_command && $project->test_command) {
                            $this->runSshCommand($connection, $project->test_command, $output);
                        }

                        $this->maybeRunLaravelMigrationsOverSsh($project, $connection, $output);
                        $this->maybeRunLaravelClearCacheOverSsh($project, $connection, $output);
                    }, $output, 'SSH initial deploy tasks');

                    if ($project->ftp_enabled) {
                        $output[] = 'FTPS sync skipped: SSH deployment is enabled for this project.';
                    }

                    $this->maybeRunSshCommands($project, $output);

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
                    $this->runSshBuildCommandWithNpmRecovery($project, $connection, $output);
                }

                if ($project->run_test_command && $project->test_command) {
                    $this->runSshCommand($connection, $project->test_command, $output);
                }

                $this->maybeRunLaravelMigrationsOverSsh($project, $connection, $output);
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
                        $this->runSshBuildCommandWithNpmRecovery($project, $connection, $output);
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

    /**
     * @return array{host: string, port: int, username: string, password: ?string, root: ?string, pass_binary: ?string, key_path: ?string}
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
        $path = preg_replace('/\.git$/', '', $path);
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

    private function remoteGitCleanCommand(Project $project, bool $dryRun): string
    {
        $command = 'git clean '.($dryRun ? '-fdn' : '-fd');
        $excludePaths = array_merge(['storage', '.env', '.htaccess', 'public/.htaccess'], $this->parseExcludePaths($project));

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

    private function runSshBuildCommandWithNpmRecovery(Project $project, array $connection, array &$output): void
    {
        $command = trim((string) $project->build_command);
        if ($command === '') {
            return;
        }

        try {
            $this->runSshCommand($connection, $command, $output);
        } catch (\Throwable $exception) {
            $manager = $this->detectBuildPackageManager($command);
            if (! $manager) {
                throw $exception;
            }

            $labelPrefix = strtoupper($manager);
            $output[] = $labelPrefix.' build failed over SSH. Removing node_modules and reinstalling dependencies.';
            $this->maybeStreamOutput($output, true);

            $this->runSshCommand($connection, 'rm -rf node_modules', $output);
            $this->runSshCommand($connection, $this->remoteInstallCommandForManager($manager), $output);
            $output[] = 'Retrying '.$labelPrefix.' build command over SSH.';
            $this->maybeStreamOutput($output, true);
            $this->runSshCommand($connection, $command, $output);
        }
    }

    private function remoteInstallCommandForManager(string $manager): string
    {
        return match ($manager) {
            'yarn' => 'yarn install',
            'pnpm' => 'pnpm install',
            default => $this->remoteNpmInstallCommand(),
        };
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

    private function maybeRunLaravelMigrationsOverSsh(Project $project, array $connection, array &$output): void
    {
        if (($project->project_type ?? '') !== 'laravel') {
            return;
        }

        $output[] = 'Running Laravel migrations over SSH.';
        $this->maybeStreamOutput($output, true);
        try {
            $this->runSshCommand(
                $connection,
                'if [ -f artisan ]; then if [ -f .env ]; then php artisan migrate --force; else echo ".env missing; skipping migrations."; fi; fi',
                $output
            );
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $isTableExists = str_contains($message, 'sqlstate[42s01]')
                || str_contains($message, 'base table or view already exists')
                || str_contains($message, 'errno: 1050')
                || (str_contains($message, 'table') && str_contains($message, 'already exists'));

            if ($project->ignore_migration_table_exists && $isTableExists) {
                $output[] = 'Warning: migration failed because tables already exist. Skipping migrations.';
                $this->maybeStreamOutput($output, true);
                return;
            }

            throw $exception;
        }
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
        $ftpService = app(\App\Services\FtpService::class);
        $ftpService->sync($project, $executionPath, $exclude, $output);

        $projectType = trim((string) ($project->project_type ?? ''));
        if ($projectType === '' || $projectType === 'laravel') {
            $ftpService->ensureRemoteLaravelDirectories($project, $output);
        }
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

        return array_values(array_unique(array_filter(array_map('trim', $paths), fn (string $path) => $path !== '')));
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
        $remoteFiles = app(\App\Services\FtpService::class)->fetchRemoteFiles($project, array_merge($composerFiles, $npmFiles), $output);

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
}
