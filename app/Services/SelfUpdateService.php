<?php

namespace App\Services;

use App\Models\AppUpdate;
use App\Models\User;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SelfUpdateService
{
    private const REPO_URL = 'https://github.com/Costigan-Stephen/gitmanager.git';
    private const DEFAULT_BRANCH = 'main';

    public function update(?User $user = null, bool $allowDirty = false): AppUpdate
    {
        $repoPath = base_path();

        $update = AppUpdate::create([
            'triggered_by' => $user?->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $output = [];
        $fromHash = null;
        $toHash = null;
        $stashed = false;
        $backupPath = null;
        $stashRestoreFailed = false;
        $htaccessSnapshot = null;

        try {
            $output[] = 'Update path: '.$repoPath;
            $htaccessSnapshot = $this->snapshotFile($repoPath, '.htaccess');
            $this->ensureGitRepository($repoPath);
            $this->ensureOriginRemote($repoPath, $output);
            $fromHash = $this->tryRevParse($repoPath);
            if ($allowDirty) {
                $stashed = $this->stashIfDirty($repoPath, $output, $htaccessSnapshot);
                if (! $fromHash) {
                    $includeDependencies = $this->shouldIncludeDependencyDirs($repoPath, $output);
                    $backupPath = $this->backupWorkingTree($repoPath, $output, $includeDependencies);
                }
            } else {
                $this->ensureCleanWorkingTree($repoPath, $output);
                if (! $fromHash) {
                    throw new \RuntimeException('Repository has no commits. Use preserve update or initialize git before updating.');
                }
            }

            $branch = $this->resolveBranch($repoPath, $output);

            $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);
            $remoteHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'origin/'.$branch], $output)->getOutput());

            if ($fromHash === $remoteHash) {
                $update->status = 'skipped';
                $update->from_hash = $fromHash;
                $update->to_hash = $fromHash;
                $update->output_log = implode("\n", $output);
                $update->finished_at = now();
                $update->save();

                return $update;
            }

            if ($fromHash) {
                $this->runProcess(['git', '-C', $repoPath, 'merge', '--ff-only', 'origin/'.$branch], $output);
            } else {
                if ($backupPath) {
                    $output[] = 'No initial commit detected; applying update with a forced checkout.';
                }
                $command = ['git', '-C', $repoPath, 'checkout', '-B', $branch, 'origin/'.$branch];
                if ($backupPath) {
                    $command[] = '--force';
                }
                $this->runProcess($command, $output);
            }
            $toHash = $this->tryRevParse($repoPath);

            if (is_file(base_path('composer.json'))) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $repoPath);
            }

            if (is_file(base_path('package.json'))) {
                $this->runProcess($this->npmInstallCommand($repoPath), $output, $repoPath);
                if ($this->npmScriptExists('build')) {
                    $this->runProcess(['npm', 'run', 'build'], $output, $repoPath);
                }
            }

            if (is_file(base_path('artisan'))) {
                $this->runProcess(['php', 'artisan', 'migrate', '--force'], $output, $repoPath);
            }

            $this->applyPostUpdatePermissions($repoPath, $output);

            if ($stashed) {
                $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                if (! $pop->isSuccessful()) {
                    $stashRestoreFailed = true;
                    throw new \RuntimeException('Update applied, but stashed changes could not be restored.');
                }
            }
            if ($backupPath) {
                $output[] = 'Backup created at: '.$backupPath;
            }

            $this->restoreSnapshot($repoPath, $htaccessSnapshot, $output);

            $update->status = 'success';
            $update->from_hash = $fromHash;
            $update->to_hash = $toHash ?: $remoteHash;
            $update->output_log = implode("\n", $output);
            $update->finished_at = now();
            $update->save();
        } catch (\Throwable $exception) {
            if ($fromHash && ! $stashRestoreFailed) {
                $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
            }

            if ($stashed && ! $stashRestoreFailed) {
                $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                if (! $pop->isSuccessful()) {
                    $output[] = 'Stash restore failed after update failure. Run `git stash pop` manually if needed.';
                }
            }

            $this->restoreSnapshot($repoPath, $htaccessSnapshot, $output);

            $update->status = 'failed';
            $update->from_hash = $fromHash;
            $update->to_hash = $toHash;
            $update->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $update->finished_at = now();
            $update->save();
        }

        return $update;
    }

    private function snapshotFile(string $repoPath, string $relative): ?array
    {
        $relative = ltrim($relative, '/\\');
        $path = $repoPath.DIRECTORY_SEPARATOR.$relative;
        if (! is_file($path)) {
            return null;
        }

        return [
            'relative' => $relative,
            'content' => file_get_contents($path),
            'mode' => fileperms($path) & 0777,
        ];
    }

    private function restoreSnapshot(string $repoPath, ?array $snapshot, array &$output): void
    {
        if (! $snapshot) {
            return;
        }

        $target = $repoPath.DIRECTORY_SEPARATOR.$snapshot['relative'];
        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($target, $snapshot['content']);
        @chmod($target, $snapshot['mode']);
        $output[] = 'Restored '.$snapshot['relative'].'.';
    }

    private function applyPostUpdatePermissions(string $repoPath, array &$output): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $paths = [
            'storage',
            'bootstrap/cache',
            'node_modules',
        ];

        foreach ($paths as $path) {
            $full = $repoPath.DIRECTORY_SEPARATOR.$path;
            if (! is_dir($full)) {
                continue;
            }

            $process = $this->runProcess(['chmod', '-R', 'ug+rwX', $path], $output, $repoPath, false);
            if (! $process->isSuccessful()) {
                $output[] = 'Warning: unable to chmod '.$path.'.';
            }
        }
    }

    private function ensureGitRepository(string $repoPath): void
    {
        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            throw new \RuntimeException('Git repository not found for this application.');
        }
    }

    private function ensureOriginRemote(string $repoPath, array &$output): void
    {
        $process = $this->runProcess(['git', '-C', $repoPath, 'remote', 'get-url', 'origin'], $output, null, false);
        if (! $process->isSuccessful()) {
            $this->runProcess(['git', '-C', $repoPath, 'remote', 'add', 'origin', self::REPO_URL], $output);
            return;
        }

        $currentUrl = trim($process->getOutput());
        if ($currentUrl === '' || $currentUrl !== self::REPO_URL) {
            $this->runProcess(['git', '-C', $repoPath, 'remote', 'set-url', 'origin', self::REPO_URL], $output);
        }
    }

    private function resolveBranch(string $repoPath, array &$output): string
    {
        $process = $this->runProcess(['git', '-C', $repoPath, 'rev-parse', '--abbrev-ref', 'HEAD'], $output, null, false);
        if (! $process->isSuccessful()) {
            return self::DEFAULT_BRANCH;
        }

        $branch = trim($process->getOutput());
        return $branch !== '' && $branch !== 'HEAD' ? $branch : self::DEFAULT_BRANCH;
    }

    private function ensureCleanWorkingTree(string $repoPath, array &$output): void
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status !== '') {
            throw new \RuntimeException('Working tree has uncommitted changes. Resolve them before updating.');
        }
    }

    private function stashIfDirty(string $repoPath, array &$output, ?array $htaccessSnapshot = null): bool
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return false;
        }

        if (! $this->tryRevParse($repoPath)) {
            $output[] = 'Local changes detected, but no initial commit exists. Skipping git stash.';
            return false;
        }

        $htaccessPath = $htaccessSnapshot ? $repoPath.DIRECTORY_SEPARATOR.$htaccessSnapshot['relative'] : null;
        if ($htaccessPath && is_file($htaccessPath)) {
            $output[] = 'Temporarily excluding .htaccess from stash.';
            @unlink($htaccessPath);
        }

        $output[] = 'Local changes detected: stashing before update.';
        $this->runProcess(['git', '-C', $repoPath, 'stash', 'push', '-u', '-m', 'gpm-self-update'], $output);

        if ($htaccessSnapshot) {
            $this->restoreSnapshot($repoPath, $htaccessSnapshot, $output);
        }

        return true;
    }

    private function getWorkingTreeStatus(string $repoPath, array &$output): string
    {
        $process = $this->runProcess(['git', '-C', $repoPath, 'status', '--porcelain'], $output, null, false);
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Unable to check git status for this application.');
        }

        return trim($process->getOutput());
    }

    private function tryRevParse(string $repoPath): ?string
    {
        $localOutput = [];
        $process = $this->runProcess(['git', '-C', $repoPath, 'rev-parse', '--verify', 'HEAD'], $localOutput, null, false);
        if (! $process->isSuccessful()) {
            return null;
        }

        $hash = trim($process->getOutput());
        return $hash !== '' ? $hash : null;
    }

    private function npmScriptExists(string $script): bool
    {
        $packagePath = base_path('package.json');
        if (! is_file($packagePath)) {
            return false;
        }

        $payload = json_decode(file_get_contents($packagePath), true);
        if (! is_array($payload)) {
            return false;
        }

        $scripts = $payload['scripts'] ?? [];
        return array_key_exists($script, $scripts);
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

    private function backupWorkingTree(string $repoPath, array &$output, bool $includeDependencies): string
    {
        $base = storage_path('app/self-update-backups');
        if (! is_dir($base)) {
            mkdir($base, 0775, true);
        }

        $timestamp = now()->format('Ymd_His');
        $target = $base.DIRECTORY_SEPARATOR.$timestamp;
        if (! is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $output[] = 'Creating backup of working tree (excluding .git, storage/logs) at: '.$target;
        if (! $includeDependencies) {
            $output[] = 'Skipping node_modules and vendor in backup (no package file changes detected).';
        }

        $backupRootRelative = ltrim(str_replace($repoPath, '', $base), DIRECTORY_SEPARATOR);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

            if ($this->shouldExcludeBackupPath($relative, $includeDependencies, $backupRootRelative)) {
                continue;
            }

            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }
                continue;
            }

            $destinationDir = dirname($destination);
            if (! is_dir($destinationDir)) {
                mkdir($destinationDir, 0775, true);
            }

            copy($item->getPathname(), $destination);
        }

        return $target;
    }

    private function shouldExcludeBackupPath(string $relative, bool $includeDependencies, string $backupRootRelative): bool
    {
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        if ($relative === '.git' || str_starts_with($relative, '.git'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        if ($backupRootRelative !== '' && ($relative === $backupRootRelative || str_starts_with($relative, $backupRootRelative.DIRECTORY_SEPARATOR))) {
            return true;
        }

        if (! $includeDependencies) {
            if ($relative === 'node_modules' || str_starts_with($relative, 'node_modules'.DIRECTORY_SEPARATOR)) {
                return true;
            }
            if ($relative === 'vendor' || str_starts_with($relative, 'vendor'.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        if ($relative === 'storage'.DIRECTORY_SEPARATOR.'logs' || str_starts_with($relative, 'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        return false;
    }

    private function shouldIncludeDependencyDirs(string $repoPath, array &$output): bool
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return false;
        }

        $targets = [
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ];

        foreach (explode("\n", $status) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }

            $basename = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path));
            if (in_array($basename, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $process = new Process($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(900);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
        });

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function normalizeCommand(array $command): array
    {
        $binary = $command[0] ?? '';
        $command[0] = match ($binary) {
            'git' => $this->gitBinary(),
            'composer' => $this->composerBinary(),
            'npm' => $this->npmBinary(),
            default => $binary,
        };

        return $command;
    }

    private function gitBinary(): string
    {
        $configured = trim((string) config('gitmanager.git_binary', env('GPM_GIT_BINARY', 'git')));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'git';
    }

    private function composerBinary(): string
    {
        $configured = trim((string) config('gitmanager.composer_binary', env('GPM_COMPOSER_BINARY', 'composer')));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'composer';
    }

    private function npmBinary(): string
    {
        $configured = trim((string) config('gitmanager.npm_binary', env('GPM_NPM_BINARY', 'npm')));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'npm';
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

        $extraPath = trim((string) config('gitmanager.process_path', env('GPM_PROCESS_PATH', '')));
        if ($extraPath !== '') {
            $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
            $current = $env[$pathKey] ?? '';
            $env[$pathKey] = $extraPath.PATH_SEPARATOR.$current;
        }

        return $env;
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
        $configured = trim((string) config('gitmanager.askpass_dir', env('GPM_ASKPASS_DIR', '')));
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
}
