<?php

namespace App\Services;

use App\Models\Project;

class LaravelDeploymentCheckService
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * @param array<int, string> $output
     */
    public function run(Project $project, string $path, array &$output): void
    {
        $projectType = trim((string) ($project->project_type ?? ''));
        if ($projectType !== '' && $projectType !== 'laravel') {
            return;
        }

        $laravelRoot = $this->findLaravelRoot($path);
        if (! $laravelRoot) {
            return;
        }

        $output[] = 'Running Laravel deployment checks.';
        $this->ensureLaravelCacheDirectories($laravelRoot, $output);
        $this->ensureLaravelStorageLink($laravelRoot, $output);
        $output[] = 'Laravel deployment checks completed.';
    }

    /**
     * @param array<int, string> $output
     */
    private function ensureLaravelCacheDirectories(string $laravelRoot, array &$output): void
    {
        $cachePath = $laravelRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data';
        $bootstrapCache = $laravelRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
        $storagePublic = $laravelRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';

        $this->permissionService->ensureWritableDirectory($storagePublic, $output, 'Laravel storage public directory');
        if (! is_dir($storagePublic) || ! is_writable($storagePublic)) {
            throw new \RuntimeException('Laravel storage public directory is not writable: '.$storagePublic);
        }

        $this->permissionService->ensureWritableDirectory($cachePath, $output, 'Laravel cache directory');
        if (! is_dir($cachePath) || ! is_writable($cachePath)) {
            throw new \RuntimeException('Laravel cache directory is not writable: '.$cachePath);
        }

        $this->permissionService->ensureWritableDirectory($bootstrapCache, $output, 'Bootstrap cache directory');
        if (! is_dir($bootstrapCache) || ! is_writable($bootstrapCache)) {
            throw new \RuntimeException('Bootstrap cache directory is not writable: '.$bootstrapCache);
        }
    }

    /**
     * @param array<int, string> $output
     */
    private function ensureLaravelStorageLink(string $laravelRoot, array &$output): void
    {
        $linkPath = $laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
        $targetPath = $laravelRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
        $publicDir = dirname($linkPath);

        if (! is_dir($publicDir)) {
            throw new \RuntimeException('Laravel public directory missing: '.$publicDir);
        }

        if (is_link($linkPath)) {
            $linkTarget = readlink($linkPath);
            if ($linkTarget !== false) {
                $resolved = $this->resolveLinkTarget($linkPath, $linkTarget);
                if ($resolved !== null && is_dir($resolved)) {
                    $output[] = 'Laravel storage link exists.';

                    return;
                }
            }

            $output[] = 'Laravel storage link is broken. Recreating.';
            @unlink($linkPath);
        } elseif (file_exists($linkPath)) {
            throw new \RuntimeException('public/storage exists but is not a symlink. Remove it and run php artisan storage:link.');
        }

        if (function_exists('symlink') && @symlink($targetPath, $linkPath)) {
            $output[] = 'Created Laravel storage link.';

            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output[] = 'Warning: unable to create Laravel storage link on Windows. Run php artisan storage:link manually.';

            return;
        }

        throw new \RuntimeException('Unable to create Laravel storage link. Run php artisan storage:link.');
    }

    private function resolveLinkTarget(string $linkPath, string $target): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        if ($this->isAbsolutePath($target)) {
            return $target;
        }

        return dirname($linkPath).DIRECTORY_SEPARATOR.$target;
    }

    private function findLaravelRoot(string $path): ?string
    {
        $cursor = $path;

        while (true) {
            if (is_file($cursor.DIRECTORY_SEPARATOR.'artisan')) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if (! $parent || $parent === $cursor) {
                break;
            }

            $cursor = $parent;
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\|^[A-Za-z]:\\//', $path);
    }
}
