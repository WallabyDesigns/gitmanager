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

    public function update(?User $user = null): AppUpdate
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

        try {
            $this->ensureGitRepository($repoPath);
            $this->ensureOriginRemote($repoPath, $output);
            $this->ensureCleanWorkingTree($repoPath, $output);

            $branch = $this->resolveBranch($repoPath, $output);

            $fromHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());
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

            $this->runProcess(['git', '-C', $repoPath, 'merge', '--ff-only', 'origin/'.$branch], $output);
            $toHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

            if (is_file(base_path('composer.json'))) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $repoPath);
            }

            if (is_file(base_path('package.json'))) {
                $this->runProcess(['npm', 'install'], $output, $repoPath);
                if ($this->npmScriptExists('build')) {
                    $this->runProcess(['npm', 'run', 'build'], $output, $repoPath);
                }
            }

            if (is_file(base_path('artisan'))) {
                $this->runProcess(['php', 'artisan', 'migrate', '--force'], $output, $repoPath);
            }

            $update->status = 'success';
            $update->from_hash = $fromHash;
            $update->to_hash = $toHash;
            $update->output_log = implode("\n", $output);
            $update->finished_at = now();
            $update->save();
        } catch (\Throwable $exception) {
            if ($fromHash) {
                $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
            }

            $update->status = 'failed';
            $update->from_hash = $fromHash;
            $update->to_hash = $toHash;
            $update->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $update->finished_at = now();
            $update->save();
        }

        return $update;
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

    private function getWorkingTreeStatus(string $repoPath, array &$output): string
    {
        $process = $this->runProcess(['git', '-C', $repoPath, 'status', '--porcelain'], $output, null, false);
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Unable to check git status for this application.');
        }

        return trim($process->getOutput());
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

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeGitCommand($command);
        $process = new Process($command, $workingDir, array_merge($_SERVER, $_ENV, $this->gitEnv()));
        $process->setTimeout(900);
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
