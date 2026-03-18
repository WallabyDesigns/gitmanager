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
        $stashed = false;

        try {
            if (! $allowDirty) {
                $stashed = $this->stashIfDirty($repoPath, $output);
            }

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
                $this->appendWorkflowOutput($deployment, $project, $output);
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

            if ($stashed) {
                $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                if (! $pop->isSuccessful()) {
                    $output[] = 'Warning: stashed changes could not be restored.';
                }
            }

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
            $project->last_checked_at = now();
            $project->save();
        } catch (\Throwable $exception) {
            if ($fromHash) {
                $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
            }

            if ($stashed) {
                $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                if (! $pop->isSuccessful()) {
                    $output[] = 'Warning: stashed changes could not be restored.';
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
            $this->appendWorkflowOutput($deployment, $project, $output);
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
            $this->appendWorkflowOutput($deployment, $project, $output);
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
            $this->appendWorkflowOutput($deployment, $project, $output);
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
            $deployment->from_hash = $fromHash;
            $deployment->to_hash = $toHash;
            $this->appendWorkflowOutput($deployment, $project, $output);
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
            $this->appendWorkflowOutput($deployment, $project, $output);
            $deployment->output_log = implode("\n", $output);
            $deployment->finished_at = now();
            $deployment->save();
        } catch (\Throwable $exception) {
            $deployment->status = 'failed';
            $this->appendWorkflowOutput($deployment, $project, $output);
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

    private function stashIfDirty(string $repoPath, array &$output): bool
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return false;
        }

        if (! $this->tryRevParse($repoPath)) {
            $output[] = 'Local changes detected, but no initial commit exists. Skipping stash.';
            return false;
        }

        $output[] = 'Local changes detected: stashing tracked changes before deploy.';
        $process = $this->runProcess([
            'git',
            '-C',
            $repoPath,
            'stash',
            'push',
            '-m',
            'gwm-deploy',
            '--',
            '.',
            ':(exclude).htaccess',
            ':(exclude)public/.htaccess',
        ], $output, null, false);

        if (! $process->isSuccessful()) {
            $output[] = 'Warning: unable to stash local changes.';
            return false;
        }

        return true;
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
        $untrackedBackup = null;

        try {
            $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', 'origin/'.$branch], $output);
        } catch (ProcessFailedException $exception) {
            $paths = $this->extractUntrackedOverwritePaths($exception);
            if ($paths === []) {
                throw $exception;
            }

            $output[] = 'Reset blocked by untracked files. Backing them up before retry.';
            $untrackedBackup = $this->backupUntrackedPaths($project, $repoPath, $paths, $output);
            if (! $untrackedBackup) {
                throw new \RuntimeException('Unable to backup untracked files blocking reset.');
            }
            $this->removeUntrackedPaths($repoPath, $paths, $output);

            $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', 'origin/'.$branch], $output);
        }

        if ($forceClean) {
            $this->runProcess($this->gitCleanCommand($project, $repoPath, false), $output);
        }

        $this->restorePreservedPaths($repoPath, $preservePath, $output);

        if ($untrackedBackup) {
            if ($forceClean) {
                $output[] = 'Untracked conflict backup kept at: '.$untrackedBackup;
            } else {
                $this->restoreUntrackedBackup($repoPath, $untrackedBackup, $output);
            }
        }
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

        $excludePaths = array_merge(['storage', '.htaccess', 'public/.htaccess'], $this->parseExcludePaths($project));
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
    private function extractUntrackedOverwritePaths(ProcessFailedException $exception): array
    {
        $text = trim($exception->getProcess()->getErrorOutput());
        if ($text === '') {
            $text = trim($exception->getProcess()->getOutput());
        }

        if ($text === '' || ! str_contains($text, 'untracked working tree files would be overwritten')) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $collect = false;
        $paths = [];

        foreach ($lines as $line) {
            if (! $collect && str_contains($line, 'would be overwritten')) {
                $collect = true;
                continue;
            }

            if (! $collect) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, 'Please ') || str_starts_with($trimmed, 'Aborting') || str_starts_with($trimmed, 'error:')) {
                break;
            }

            $path = $this->sanitizeUntrackedPath($trimmed);
            if ($path === null) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private function sanitizeUntrackedPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_contains($path, ' -> ')) {
            $parts = explode(' -> ', $path);
            $path = trim(end($parts));
        }

        $path = ltrim($path, "./");
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        if ($path === '' || $path === '.' || $path === '..') {
            return null;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '/').'/', $path) === 1) {
            return null;
        }

        if (str_contains($path, '..'.DIRECTORY_SEPARATOR) || str_starts_with($path, '..')) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<int, string> $paths
     */
    private function backupUntrackedPaths(Project $project, string $repoPath, array $paths, array &$output): ?string
    {
        if ($paths === []) {
            return null;
        }

        $base = storage_path('app/deploy-untracked/'.$project->id.'/'.now()->format('Ymd_His'));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            $output[] = 'Unable to create untracked backup directory: '.$base;
            return null;
        }

        $output[] = 'Backing up '.count($paths).' untracked path(s) blocking reset.';

        foreach ($paths as $relative) {
            $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $source = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (! file_exists($source)) {
                continue;
            }

            $destination = $base.DIRECTORY_SEPARATOR.$relative;
            $this->copyPath($source, $destination);
        }

        return $base;
    }

    /**
     * @param array<int, string> $paths
     */
    private function removeUntrackedPaths(string $repoPath, array $paths, array &$output): void
    {
        foreach ($paths as $relative) {
            $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $path = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $output[] = 'Removed untracked files blocking reset.';
    }

    private function restoreUntrackedBackup(string $repoPath, string $backupPath, array &$output): void
    {
        if (! is_dir($backupPath)) {
            return;
        }

        $restored = 0;
        $skipped = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            $target = $repoPath.DIRECTORY_SEPARATOR.$relative;

            if (file_exists($target)) {
                $skipped++;
                continue;
            }

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
            $restored++;
        }

        if ($restored > 0) {
            $output[] = 'Restored '.$restored.' untracked path(s) after reset.';
        }
        if ($skipped > 0) {
            $output[] = 'Skipped '.$skipped.' untracked path(s) because targets now exist. Backup kept at: '.$backupPath;
        }
    }

    /**
     * @return array<int, string>
     */
    private function getPreservePaths(Project $project): array
    {
        $paths = ['.htaccess', 'public/.htaccess'];

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

    private function appendWorkflowOutput(Deployment $deployment, Project $project, array &$output): void
    {
        try {
            $messages = app(WorkflowService::class)->handleDeployment($deployment, $project);
            foreach ($messages as $message) {
                $output[] = $message;
            }
        } catch (\Throwable $exception) {
            $output[] = 'Workflow notifications failed: '.$exception->getMessage();
        }
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
        $trimmed = ltrim($command);
        if (str_starts_with($trimmed, 'php ')) {
            $command = $this->phpBinary().' '.substr($trimmed, 4);
        }

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
            'php' => $this->phpBinary(),
            default => $binary,
        };

        return $command;
    }

    private function gitBinary(): string
    {
        $configured = trim((string) config('gitmanager.git_binary', 'git'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'git';
    }

    private function composerBinary(): string
    {
        $configured = trim((string) config('gitmanager.composer_binary', 'composer'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'composer';
    }

    private function npmBinary(): string
    {
        $configured = trim((string) config('gitmanager.npm_binary', 'npm'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'npm';
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('gitmanager.php_binary', 'php'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'php';
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

        $extraPath = trim((string) config('gitmanager.process_path', ''));
        $extraPath = trim($extraPath, "\"' ");
        if ($extraPath !== '') {
            $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
            $current = $env[$pathKey] ?? '';
            $env[$pathKey] = $extraPath.PATH_SEPARATOR.$current;
        }

        $phpBinary = $this->phpBinary();
        if (str_contains($phpBinary, DIRECTORY_SEPARATOR) || str_contains($phpBinary, '/')) {
            $phpDir = dirname($phpBinary);
            if ($phpDir !== '' && $phpDir !== '.') {
                $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
                $current = $env[$pathKey] ?? '';
                if (! str_contains($current, $phpDir)) {
                    $env[$pathKey] = $phpDir.PATH_SEPARATOR.$current;
                }
            }
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
