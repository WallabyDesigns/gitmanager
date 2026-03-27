<?php

namespace App\Services;

use App\Models\Project;
use Symfony\Component\Process\Process;

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

        if ($this->storageLinkIsValid($linkPath, $targetPath)) {
            $output[] = 'Laravel storage link exists.';

            return;
        }

        if (is_link($linkPath)) {
            $output[] = 'Laravel storage link is broken. Recreating.';
            @unlink($linkPath);
        } elseif (file_exists($linkPath)) {
            throw new \RuntimeException('public/storage exists but is not a symlink. Remove it and run php artisan storage:link.');
        }

        if (function_exists('symlink') && @symlink($targetPath, $linkPath)) {
            $output[] = 'Created Laravel storage link.';

            return;
        }

        $output[] = 'Symlink creation failed. Attempting php artisan storage:link.';
        if ($this->tryArtisanStorageLink($laravelRoot, $output) && $this->storageLinkIsValid($linkPath, $targetPath)) {
            $output[] = 'Laravel storage link created by artisan.';

            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output[] = 'Attempting Windows junction for storage link.';
            if ($this->tryWindowsJunction($linkPath, $targetPath, $output) && $this->storageLinkIsValid($linkPath, $targetPath)) {
                $output[] = 'Laravel storage link created as a Windows junction.';

                return;
            }
        }

        throw new \RuntimeException('Unable to create Laravel storage link. Run php artisan storage:link.');
    }

    private function storageLinkIsValid(string $linkPath, string $targetPath): bool
    {
        if (is_link($linkPath)) {
            $linkTarget = readlink($linkPath);
            if ($linkTarget === false) {
                return false;
            }

            $resolved = $this->resolveLinkTarget($linkPath, $linkTarget);
            return $resolved !== null && is_dir($resolved);
        }

        if (is_dir($linkPath)) {
            $linkReal = realpath($linkPath);
            $targetReal = realpath($targetPath);
            if ($linkReal && $targetReal) {
                return PHP_OS_FAMILY === 'Windows'
                    ? strcasecmp($linkReal, $targetReal) === 0
                    : $linkReal === $targetReal;
            }
        }

        return false;
    }

    private function tryArtisanStorageLink(string $laravelRoot, array &$output): bool
    {
        $phpBinary = $this->phpBinary();
        $command = [$phpBinary, 'artisan', 'storage:link'];

        $process = new Process($command, $laravelRoot);
        $process->setTimeout(120);
        $process->run();

        $stdout = trim($process->getOutput());
        if ($stdout !== '') {
            $output[] = $stdout;
        }

        $stderr = trim($process->getErrorOutput());
        if ($stderr !== '') {
            $output[] = $stderr;
        }

        return $process->isSuccessful();
    }

    private function tryWindowsJunction(string $linkPath, string $targetPath, array &$output): bool
    {
        $process = new Process(['cmd', '/c', 'mklink', '/J', $linkPath, $targetPath]);
        $process->setTimeout(120);
        $process->run();

        $stdout = trim($process->getOutput());
        if ($stdout !== '') {
            $output[] = $stdout;
        }

        $stderr = trim($process->getErrorOutput());
        if ($stderr !== '') {
            $output[] = $stderr;
        }

        return $process->isSuccessful();
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

    private function phpBinary(): string
    {
        $configured = trim((string) config('gitmanager.php_binary', 'php'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'php';
    }
}
