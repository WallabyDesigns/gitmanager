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

        $this->runProcess(['git', 'init'], $output, $path);
        $this->runProcess(['git', 'remote', 'add', 'origin', $project->repo_url], $output, $path);
        $this->runProcess(['git', 'fetch', '--all', '--prune'], $output, $path);
        $this->runProcess(['git', 'checkout', '-b', $branch], $output, $path);
        $this->runProcess(['git', 'reset', '--mixed', 'origin/'.$branch], $output, $path);

        $status = trim($this->runProcess(['git', 'status', '--porcelain'], $output, $path, false)->getOutput());

        return [
            'status' => 'bootstrapped',
            'dirty' => $status !== '',
            'output' => $output,
        ];
    }

    private function findGitRoot(string $path): ?string
    {
        $cursor = $path;

        while (true) {
            if (is_dir($cursor.DIRECTORY_SEPARATOR.'.git')) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if (! $parent || $parent === $cursor) {
                return null;
            }

            $cursor = $parent;
        }
    }

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeGitCommand($command);
        $process = new Process($command, $workingDir, array_merge($_SERVER, $_ENV, $this->gitEnv()));
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
        $configured = trim((string) config('gitmanager.git_binary', env('GPM_GIT_BINARY', 'git')));
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

    private function ensureAskPassScript(): ?string
    {
        $storage = storage_path('app');
        if (! is_dir($storage)) {
            mkdir($storage, 0775, true);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $path = $storage.DIRECTORY_SEPARATOR.'git-askpass.bat';
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

        $path = $storage.DIRECTORY_SEPARATOR.'git-askpass.sh';
        if (! file_exists($path)) {
            file_put_contents($path, "#!/bin/sh\n"
                ."case \"$1\" in\n"
                ."  *Username*) echo \"$GIT_USERNAME\";;\n"
                ."  *) echo \"$GIT_PASSWORD\";;\n"
                ."esac\n");
            @chmod($path, 0755);
        }

        return $path;
    }
}
