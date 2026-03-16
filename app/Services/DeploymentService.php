<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DeploymentService
{
    public function checkForUpdates(Project $project): bool
    {
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

            $this->resetToRemote($project, $repoPath, $project->default_branch, $output, $allowDirty);

            $toHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], $output)->getOutput());

            if ($project->run_composer_install) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $executionPath);
            }

            if ($project->run_npm_install) {
                $this->runProcess($this->npmInstallCommand($executionPath), $output, $executionPath);
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
            $this->ensureCleanWorkingTree($repoPath, $output, true);
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

            if ($project->run_composer_install) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $executionPath);
            }

            if ($project->run_npm_install) {
                $this->runProcess($this->npmInstallCommand($executionPath), $output, $executionPath);
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
            $this->ensureCleanWorkingTree($repoPath, $output, true);
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

    public function composerInstall(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'composer_install', function (string $path, array &$output): void {
            $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $path);
        }, true);
    }

    public function composerUpdate(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'composer_update', function (string $path, array &$output): void {
            $this->runProcess(['composer', 'update'], $output, $path);
        }, true);
    }

    public function composerAudit(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'composer_audit', function (string $path, array &$output): void {
            $this->runProcess(['composer', 'audit'], $output, $path);
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

            $this->runProcess(['php', 'artisan', 'app:clear-cache'], $output, $laravelRoot);
        });
    }

    public function npmInstall(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'npm_install', function (string $path, array &$output): void {
            $this->runProcess(['npm', 'install'], $output, $path);
        });
    }

    public function npmUpdate(Project $project, ?User $user = null): Deployment
    {
        return $this->runMaintenanceAction($project, $user, 'npm_update', function (string $path, array &$output): void {
            $this->runProcess(['npm', 'update'], $output, $path);
        });
    }

    public function npmAuditFix(Project $project, ?User $user = null, bool $force = false): Deployment
    {
        return $this->runMaintenanceAction($project, $user, $force ? 'npm_audit_fix_force' : 'npm_audit_fix', function (string $path, array &$output) use ($force): void {
            $command = ['npm', 'audit', 'fix'];
            if ($force) {
                $command[] = '--force';
            }
            $this->runProcess($command, $output, $path);
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
            $this->runShellCommand($command, $output, $path);
        });
    }

    public function previewBuild(Project $project, ?User $user = null, string $commit = ''): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'preview_build',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $output = [];

        try {
            $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);

            $target = trim($commit) !== '' ? trim($commit) : 'origin/'.$project->default_branch;
            $hash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', $target], $output)->getOutput());
            $short = substr($hash, 0, 7);

            $slug = Str::slug($project->name) ?: 'project';
            $basePath = rtrim((string) config('gitmanager.preview.path', storage_path('app/previews')), DIRECTORY_SEPARATOR);
            $previewPath = $basePath.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$short;

            $this->ensurePath(dirname($previewPath));

            if (is_dir($previewPath)) {
                $this->runProcess(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $previewPath], $output, null, false);
                $this->deleteDirectory($previewPath);
            }

            $this->runProcess(['git', '-C', $repoPath, 'worktree', 'add', '--force', $previewPath, $hash], $output);
            $output[] = 'Preview path: '.$previewPath;

            $baseUrl = trim((string) config('gitmanager.preview.base_url', ''));
            if ($baseUrl !== '') {
                $output[] = 'Preview url: '.rtrim($baseUrl, '/').'/'.$slug.'/'.$short;
            }

            if ($project->run_composer_install && is_file($previewPath.DIRECTORY_SEPARATOR.'composer.json')) {
                $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $previewPath);
            }

            if ($project->run_npm_install && is_file($previewPath.DIRECTORY_SEPARATOR.'package.json')) {
            $this->runProcess($this->npmInstallCommand($previewPath), $output, $previewPath);
            }

            if ($project->run_build_command && $project->build_command) {
                $this->runShellCommand($project->build_command, $output, $previewPath);
            }

            if ($project->run_test_command && $project->test_command) {
                $this->runShellCommand($project->test_command, $output, $previewPath);
            }

            $deployment->status = 'success';
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
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
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new \RuntimeException('Project path not found: '.$path);
        }
    }

    private function resolveRepoPath(Project $project): string
    {
        $this->ensurePath($project->local_path);

        $repoPath = $project->local_path;
        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            if (! $project->repo_url) {
                throw new \RuntimeException('Repository URL is required to initialize git for this project.');
            }

            app(RepositoryBootstrapper::class)->bootstrap($project);
        }

        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            throw new \RuntimeException('No git repository found at: '.$project->local_path);
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
        $candidate = $path.DIRECTORY_SEPARATOR.'.git';
        return is_dir($candidate) ? $path : null;
    }

    private function ensureCleanWorkingTree(string $repoPath, array &$output, bool $strict = false): void
    {
        if (! $strict) {
            return;
        }

        if ($this->workingTreeDirty($repoPath, $output)) {
            throw new \RuntimeException('Working tree has uncommitted changes. Resolve them before deploying.');
        }
    }

    private function resetToRemote(Project $project, string $repoPath, string $branch, array &$output, bool $forceClean): void
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status !== '') {
            $output[] = $forceClean
                ? 'Force deploy requested: tracked files will be reset and untracked files removed.'
                : 'Local changes detected: tracked files will be reset, untracked files preserved.';
        }

        $preservePath = $this->snapshotPreservePaths($project, $repoPath, $output);
        $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', 'origin/'.$branch], $output);

        if ($forceClean) {
            $this->runProcess($this->gitCleanCommand($project, $repoPath, false), $output);
        }

        $this->restorePreservedPaths($repoPath, $preservePath, $output);
    }

    private function forceCleanWorkingTree(Project $project, string $repoPath, array &$output): void
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

        $cleanPreview = $this->runProcess($this->gitCleanCommand($project, $repoPath, true), $output, null, false);
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
        $this->runProcess($this->gitCleanCommand($project, $repoPath, false), $output);
    }

    /**
     * @return array<int, string>
     */
    private function gitCleanCommand(Project $project, string $repoPath, bool $dryRun): array
    {
        $command = ['git', '-C', $repoPath, 'clean', $dryRun ? '-fdn' : '-fd'];

        $excludePaths = array_merge(['storage', '.htaccess'], $this->parseExcludePaths($project));
        foreach (array_unique($excludePaths) as $path) {
            $path = trim($path);
            if ($path === '' || $path === '.' || $path === '..') {
                continue;
            }

            $path = ltrim($path, '/\\');
            if ($path === '') {
                continue;
            }

            $command[] = '-e';
            $command[] = $path;
        }

        return $command;
    }

    private function snapshotPreservePaths(Project $project, string $repoPath, array &$output): ?string
    {
        $preserve = $this->getPreservePaths($project);
        if ($preserve === []) {
            return null;
        }

        $base = storage_path('app/deploy-preserve/'.$project->id.'/'.now()->format('Ymd_His'));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            $output[] = 'Unable to create preserve directory: '.$base;
            return null;
        }

        $matches = $this->collectPreserveTargets($repoPath, $preserve);
        if ($matches === []) {
            return null;
        }

        $output[] = 'Preserving '.count($matches).' path(s) before reset.';

        foreach ($matches as $relative) {
            $source = $repoPath.DIRECTORY_SEPARATOR.$relative;
            $destination = $base.DIRECTORY_SEPARATOR.$relative;
            $this->copyPath($source, $destination);
        }

        return $base;
    }

    private function restorePreservedPaths(string $repoPath, ?string $preservePath, array &$output): void
    {
        if (! $preservePath || ! is_dir($preservePath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($preservePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            $target = $repoPath.DIRECTORY_SEPARATOR.$relative;

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

        $output[] = 'Restored preserved paths.';
    }

    /**
     * @return array<int, string>
     */
    private function getPreservePaths(Project $project): array
    {
        $paths = ['.htaccess'];

        foreach ($this->parseExcludePaths($project) as $path) {
            $paths[] = $path;
        }

        return array_values(array_unique(array_filter($paths, fn (string $path) => trim($path) !== '')));
    }

    /**
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    private function collectPreserveTargets(string $repoPath, array $patterns): array
    {
        $matches = [];
        $hasWildcard = false;
        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                $hasWildcard = true;
                break;
            }
        }

        $normalizedPatterns = array_map(function (string $pattern): string {
            $pattern = ltrim($pattern, '/\\');
            return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $pattern);
        }, $patterns);

        $direct = [];
        foreach ($normalizedPatterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                $direct[] = $pattern;
            }
        }

        foreach ($direct as $path) {
            $full = $repoPath.DIRECTORY_SEPARATOR.$path;
            if (file_exists($full)) {
                $matches[] = $path;
            }
        }

        if (! $hasWildcard) {
            return array_values(array_unique($matches));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() || $item->isLink()) {
                continue;
            }

            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

            foreach ($normalizedPatterns as $pattern) {
                if (str_contains($pattern, '*') && fnmatch($pattern, $relative)) {
                    $matches[] = $relative;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
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

    /**
     * @return array<int, string>
     */
    private function parseExcludePaths(Project $project): array
    {
        $raw = (string) ($project->exclude_paths ?? '');
        if ($raw === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $normalized = str_replace(',', "\n", $normalized);

        $paths = [];
        foreach (explode("\n", $normalized) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $paths[] = $line;
        }

        return $paths;
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

    private function tryRevParse(string $repoPath): ?string
    {
        $output = [];
        $process = $this->runProcess(['git', '-C', $repoPath, 'rev-parse', '--verify', 'HEAD'], $output, null, false);
        if (! $process->isSuccessful()) {
            return null;
        }

        $hash = trim($process->getOutput());
        return $hash !== '' ? $hash : null;
    }

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
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
            $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $deployment->finished_at = now();
            $deployment->save();

            $project->last_error_message = $exception->getMessage();
            $project->save();
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

    private function getRepoPathIfExists(Project $project): ?string
    {
        $path = $project->local_path;
        return is_dir($path.DIRECTORY_SEPARATOR.'.git') ? $path : null;
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

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
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

    private function runShellCommand(string $command, array &$output = [], ?string $workingDir = null): Process
    {
        $process = Process::fromShellCommandline($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
        });

        if (! $process->isSuccessful()) {
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
