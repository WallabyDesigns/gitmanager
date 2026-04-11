<?php

namespace App\Services\Concerns;

use App\Models\Project;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait ManagesGitWorkingTree
{
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
            ':(exclude).env',
            ':(exclude).htaccess',
            ':(exclude)public/.htaccess',
        ], $output, null, false);

        if (! $process->isSuccessful()) {
            $output[] = 'Warning: unable to stash local changes.';

            return false;
        }

        return true;
    }

    private function restoreStashOrReset(string $repoPath, array &$output, ?string $resetTo = null): void
    {
        $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
        if ($pop->isSuccessful()) {
            return;
        }

        $output[] = 'Warning: stashed changes could not be restored. Leaving stash intact and resetting working tree.';
        $target = $resetTo ?: 'HEAD';
        $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $target], $output, null, false);
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

            $command[] = '-e';
            $command[] = $path;
        }

        return $command;
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

        $path = ltrim($path, './');
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
        $paths = ['.env', '.htaccess', 'public/.htaccess'];

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
}
