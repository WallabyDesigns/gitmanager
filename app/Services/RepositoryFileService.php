<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RepositoryFileService
{
    public function readFile(string $repoUrl, string $branch, string $path): ?string
    {
        $repoUrl = trim($repoUrl);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        $path = ltrim($path, '/');

        if ($repoUrl === '' || $path === '') {
            return null;
        }

        $repoUrl = $this->normalizeRepoUrl($repoUrl);
        $temp = storage_path('app/repo-preview/'.uniqid('repo-', true));

        if (! @mkdir($temp, 0775, true) && ! is_dir($temp)) {
            return null;
        }

        try {
            $this->runProcess(['git', 'init'], $temp);
            $this->runProcess(['git', 'remote', 'add', 'origin', $repoUrl], $temp);
            $this->runProcess(['git', 'fetch', '--depth=1', 'origin', $branch], $temp);

            $process = $this->runProcess([
                'git',
                'show',
                'FETCH_HEAD:'.$path,
            ], $temp, false);

            if (! $process->isSuccessful()) {
                return null;
            }

            $contents = $process->getOutput();

            return $contents === '' ? null : $contents;
        } catch (\Throwable $exception) {
            return null;
        } finally {
            $this->deleteDirectory($temp);
        }
    }

    private function runProcess(array $command, string $workingDir, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeGitCommand($command);
        $process = new Process($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(60);
        $process->run();

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function normalizeGitCommand(array $command): array
    {
        if (($command[0] ?? '') !== 'git') {
            return $command;
        }

        $command[0] = $this->gitBinary();

        return $command;
    }

    private function gitBinary(): string
    {
        $configured = trim((string) config('gitmanager.git_binary', 'git'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'git';
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

        return is_array($env) ? $env : [];
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
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
