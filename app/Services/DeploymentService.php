<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DeploymentService
{
    public function checkForUpdates(Project $project): bool
    {
        $repoPath = $this->resolveRepoPath($project);

        $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune']);
        $head = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'])->getOutput());
        $remote = trim($this->runProcess([
            'git',
            '-C',
            $repoPath,
            'rev-parse',
            'origin/'.$project->default_branch,
        ])->getOutput());

        $project->last_checked_at = now();
        $project->save();

        return $head !== $remote;
    }

    public function deploy(Project $project, ?User $user = null, bool $allowDirty = false): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'deploy',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $output = [];
        $fromHash = null;
        $toHash = null;

        try {
            if ($allowDirty) {
                $this->forceCleanWorkingTree($repoPath, $output);
            } else {
                $this->ensureCleanWorkingTree($repoPath, $output);
            }

            $fromHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());
            $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);
            $remoteHash = trim($this->runProcess([
                'git',
                '-C',
                $repoPath,
                'rev-parse',
                'origin/'.$project->default_branch,
            ], $output)->getOutput());

            if ($fromHash === $remoteHash) {
                $deployment->status = 'success';
                $deployment->from_hash = $fromHash;
                $deployment->to_hash = $fromHash;
                $deployment->output_log = implode("\n", $output);
                $deployment->finished_at = now();
                $deployment->save();

                $project->last_checked_at = now();
                $project->save();

                return $deployment;
            }

            $this->runProcess([
                'git',
                '-C',
                $repoPath,
                'merge',
                '--ff-only',
                'origin/'.$project->default_branch,
            ], $output);

            $toHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

            if ($project->run_composer_install) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $executionPath);
            }

            if ($project->run_npm_install) {
                $this->runProcess(['npm', 'install'], $output, $executionPath);
            }

            if ($project->run_build_command && $project->build_command) {
                $this->runShellCommand($project->build_command, $output, $executionPath);
            }

            if ($project->run_test_command && $project->test_command) {
                $this->runShellCommand($project->test_command, $output, $executionPath);
            }

            $this->maybeRunLaravelClearCache($project, $output);

            $deployment->status = 'success';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_deployed_at = now();
            $project->last_deployed_hash = $toHash;
            $project->last_error_message = null;
            $project->last_checked_at = now();
            $project->save();
        } catch (\Throwable $exception) {
            if ($fromHash) {
                $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
            }

            $deployment->status = 'failed';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_error_message = $exception->getMessage();
            $project->last_checked_at = now();
            $project->save();
        }

        return $deployment;
    }

    public function rollback(Project $project, ?User $user = null, ?string $targetHash = null): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);
        $executionPath = $this->resolveExecutionPath($project, $repoPath);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'rollback',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $output = [];
        $fromHash = null;
        $toHash = $targetHash;

        try {
            $this->ensureCleanWorkingTree($repoPath, $output);
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

            $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $toHash], $output);

            if ($project->run_composer_install) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $executionPath);
            }

            if ($project->run_npm_install) {
                $this->runProcess(['npm', 'install'], $output, $executionPath);
            }

            if ($project->run_build_command && $project->build_command) {
                $this->runShellCommand($project->build_command, $output, $executionPath);
            }

            $this->maybeRunLaravelClearCache($project, $output);

            $deployment->status = 'success';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_deployed_at = now();
            $project->last_deployed_hash = $toHash;
            $project->last_error_message = null;
            $project->save();
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_error_message = $exception->getMessage();
            $project->save();
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

        $output = [];
        $fromHash = null;
        $toHash = null;

        try {
            $this->ensureCleanWorkingTree($repoPath, $output);
            $fromHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

            if (! $project->allow_dependency_updates) {
                throw new \RuntimeException('Dependency updates are disabled for this project.');
            }

            if ($project->run_composer_install) {
                $this->runProcess(['composer', 'update'], $output, $executionPath);
            }

            if ($project->run_npm_install) {
                $this->runProcess(['npm', 'update'], $output, $executionPath);
            }

            if ($project->run_build_command && $project->build_command) {
                $this->runShellCommand($project->build_command, $output, $executionPath);
            }

            if ($project->run_test_command && $project->test_command) {
                $this->runShellCommand($project->test_command, $output, $executionPath);
            }

            $this->maybeRunLaravelClearCache($project, $output);

            $toHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

            $deployment->status = 'success';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $deployment->finished_at = now();
            $deployment->save();
        }

        return $deployment;
    }

    public function checkHealth(Project $project): string
    {
        $healthUrl = $this->resolveHealthUrl($project);

        if (! $healthUrl) {
            $project->health_status = 'unknown';
            $project->health_checked_at = now();
            $project->save();

            return 'unknown';
        }

        try {
            $response = Http::timeout(10)->get($healthUrl);
            $status = $response->successful() ? 'ok' : 'fail';
        } catch (\Throwable $exception) {
            $status = 'fail';
        }

        $project->health_status = $status;
        $project->health_checked_at = now();
        $project->save();

        return $status;
    }

    private function ensurePath(string $path): void
    {
        if (! is_dir($path)) {
            throw new \RuntimeException('Project path not found: '.$path);
        }
    }

    private function resolveRepoPath(Project $project): string
    {
        $this->ensurePath($project->local_path);

        $repoPath = $this->findGitRoot($project->local_path);
        if (! $repoPath) {
            throw new \RuntimeException('No git repository found at or above: '.$project->local_path);
        }

        return $repoPath;
    }

    private function resolveExecutionPath(Project $project, string $repoPath): string
    {
        $laravelRoot = $this->findLaravelRoot($project->local_path)
            ?? $this->findLaravelRoot($repoPath);

        return $laravelRoot ?: $repoPath;
    }

    private function findGitRoot(string $path): ?string
    {
        return $this->walkUpForMarker($path, '.git');
    }

    private function ensureCleanWorkingTree(string $repoPath, array &$output): void
    {
        if ($this->workingTreeDirty($repoPath, $output)) {
            throw new \RuntimeException('Working tree has uncommitted changes. Resolve them before deploying.');
        }
    }

    private function forceCleanWorkingTree(string $repoPath, array &$output): void
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

        $cleanPreview = $this->runProcess(['git', '-C', $repoPath, 'clean', '-fdn'], $output, null, false);
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
        $this->runProcess(['git', '-C', $repoPath, 'clean', '-fd'], $output);
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

    private function runShellCommand(string $command, array &$output = [], ?string $workingDir = null): Process
    {
        $process = Process::fromShellCommandline($command, $workingDir, array_merge($_SERVER, $_ENV, $this->gitEnv()));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
        });

        if (! $process->isSuccessful()) {
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

    private function maybeRunLaravelClearCache(Project $project, array &$output): void
    {
        $laravelRoot = $this->findLaravelRoot($project->local_path);
        if (! $laravelRoot) {
            return;
        }

        if (! $this->artisanCommandExists($laravelRoot, 'app:clear-cache', $output)) {
            return;
        }

        $this->runProcess(['php', 'artisan', 'app:clear-cache'], $output, $laravelRoot);
    }

    private function artisanCommandExists(string $path, string $command, array &$output): bool
    {
        $process = $this->runProcess(['php', 'artisan', 'list', '--format=json'], $output, $path, false);

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
        $envPath = $path.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envPath)) {
            return null;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_starts_with($line, 'APP_URL=')) {
                continue;
            }

            $value = trim(substr($line, strlen('APP_URL=')));
            $value = trim($value, "\"'");
            return $value !== '' ? $value : null;
        }

        return null;
    }
}
