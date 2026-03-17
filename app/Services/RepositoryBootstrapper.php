<?php

namespace App\Services;

use App\Models\Project;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RepositoryBootstrapper
{
    /**
     * @return array{status: string, dirty?: bool, output?: array<int, string>}
     */
    public function bootstrap(Project $project): array
    {
        $path = $project->local_path;

        if (! is_dir($path)) {
            throw new \RuntimeException('Project path not found: '.$path);
        }

        if ($this->findGitRoot($path)) {
            return ['status' => 'exists'];
        }

        if (! $project->repo_url) {
            throw new \RuntimeException('Repository URL is required to initialize git for this project.');
        }

        $output = [];
        $branch = $project->default_branch ?: 'main';
        $repoUrl = $this->normalizeRepoUrl($project->repo_url);

        $this->runProcess(['git', 'init'], $output, $path);
        $this->runProcess(['git', 'remote', 'add', 'origin', $repoUrl], $output, $path);
        $this->runProcess(['git', 'fetch', '--all', '--prune'], $output, $path);
        $this->runProcess(['git', 'checkout', '-B', $branch], $output, $path);

        if ($this->isDirectoryEmpty($path, ['.git'])) {
            $this->runProcess(['git', 'reset', '--hard', 'origin/'.$branch], $output, $path);
        } else {
            $this->runProcess(['git', 'reset', '--mixed', 'origin/'.$branch], $output, $path);
        }

        $status = trim($this->runProcess(['git', 'status', '--porcelain'], $output, $path, false)->getOutput());

        return [
            'status' => 'bootstrapped',
            'dirty' => $status !== '',
            'output' => $output,
        ];
    }

    private function isDirectoryEmpty(string $path, array $ignore = []): bool
    {
        $ignoreLookup = array_fill_keys($ignore, true);
        $entries = scandir($path) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (isset($ignoreLookup[$entry])) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function normalizeRepoUrl(string $repoUrl): string
    {
        $repoUrl = trim($repoUrl);
        if ($repoUrl === '') {
            throw new \RuntimeException('Repository URL is required to initialize git for this project.');
        }

        if (str_starts_with($repoUrl, 'git@') || str_contains($repoUrl, '://')) {
            return $repoUrl;
        }

        // Allow shorthand "owner/repo" by expanding to GitHub HTTPS URL.
        if (substr_count($repoUrl, '/') === 1) {
            return 'https://github.com/'.$repoUrl.(str_ends_with($repoUrl, '.git') ? '' : '.git');
        }

        return $repoUrl;
    }

    private function findGitRoot(string $path): ?string
    {
        return is_dir($path.DIRECTORY_SEPARATOR.'.git') ? $path : null;
    }

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeGitCommand($command);
        $process = new Process($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
        });

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
}
