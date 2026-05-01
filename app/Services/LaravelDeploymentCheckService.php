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
     * @param  array<int, string>  $output
     */
    public function run(Project $project, string $path, array &$output, bool $requireEnv = true): void
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
        if ($requireEnv) {
            $this->ensureLaravelEnvFile($laravelRoot, $output);
        } else {
            $output[] = 'Skipping Laravel .env check for staging.';
        }
        $this->ensureLaravelCacheDirectories($laravelRoot, $output);
        $this->ensureLaravelHtaccessFiles($laravelRoot, $output);
        $this->ensureLaravelPublicIndexPriority($laravelRoot, $output);
        $this->ensureLaravelStorageLink($laravelRoot, $output);
        $output[] = 'Laravel deployment checks completed.';
    }

    /**
     * @param  array<int, string>  $output
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
     * @param  array<int, string>  $output
     */
    private function ensureLaravelEnvFile(string $laravelRoot, array &$output): void
    {
        $envPath = $laravelRoot.DIRECTORY_SEPARATOR.'.env';
        if (is_file($envPath)) {
            $contents = @file_get_contents($envPath);
            if ($contents === false || trim($contents) === '') {
                throw new \RuntimeException('Laravel .env file is empty: '.$envPath);
            }
            $output[] = 'Laravel .env file found.';

            return;
        }

        $example = $laravelRoot.DIRECTORY_SEPARATOR.'.env.example';
        if (is_file($example)) {
            throw new \RuntimeException('Laravel .env file missing at '.$envPath.'. Copy .env.example and configure it before deploying.');
        }

        throw new \RuntimeException('Laravel .env file missing at '.$envPath.'. Create one before deploying.');
    }

    /**
     * @param  array<int, string>  $output
     */
    private function ensureLaravelHtaccessFiles(string $laravelRoot, array &$output): void
    {
        $publicDir = $laravelRoot.DIRECTORY_SEPARATOR.'public';
        if (! is_dir($publicDir)) {
            throw new \RuntimeException('Laravel public directory missing: '.$publicDir);
        }

        $rootHtaccess = $laravelRoot.DIRECTORY_SEPARATOR.'.htaccess';
        $publicHtaccess = $publicDir.DIRECTORY_SEPARATOR.'.htaccess';

        $this->ensureHtaccessFile(
            $rootHtaccess,
            $this->rootHtaccessTemplate($laravelRoot),
            $output,
            'Root .htaccess'
        );
        $this->ensureHtaccessFile(
            $publicHtaccess,
            $this->publicHtaccessTemplate(),
            $output,
            'Public .htaccess'
        );
    }

    /**
     * @param  array<int, string>  $output
     */
    private function ensureLaravelPublicIndexPriority(string $laravelRoot, array &$output): void
    {
        $publicDir = $laravelRoot.DIRECTORY_SEPARATOR.'public';
        $indexPhp = $publicDir.DIRECTORY_SEPARATOR.'index.php';

        if (! is_file($indexPhp)) {
            return;
        }

        $candidates = [
            ['path' => $laravelRoot.DIRECTORY_SEPARATOR.'index.html', 'label' => 'Root index.html'],
            ['path' => $laravelRoot.DIRECTORY_SEPARATOR.'index.htm', 'label' => 'Root index.htm'],
            ['path' => $publicDir.DIRECTORY_SEPARATOR.'index.html', 'label' => 'Public index.html'],
            ['path' => $publicDir.DIRECTORY_SEPARATOR.'index.htm', 'label' => 'Public index.htm'],
        ];

        foreach ($candidates as $candidate) {
            $path = $candidate['path'];
            if (! is_file($path)) {
                continue;
            }

            $backupPath = $this->uniqueBackupPath($path);
            if (! @rename($path, $backupPath)) {
                throw new \RuntimeException('Unable to rename '.$path.' so Laravel can serve index.php.');
            }

            $output[] = $candidate['label'].' renamed to '.basename($backupPath).' so Laravel index.php takes priority.';
        }
    }

    private function uniqueBackupPath(string $path): string
    {
        $dir = dirname($path);
        $base = basename($path);
        $timestamp = date('Ymd_His');
        $suffix = $base.'.gwm-backup-'.$timestamp;
        $candidate = $dir.DIRECTORY_SEPARATOR.$suffix;
        $counter = 1;

        while (file_exists($candidate)) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$suffix.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    /**
     * @param  array<int, string>  $output
     */
    private function ensureHtaccessFile(string $path, ?string $template, array &$output, string $label): void
    {
        if (is_file($path)) {
            $contents = @file_get_contents($path);
            if ($contents === false || trim($contents) === '') {
                $output[] = $label.' exists but is empty. Recreating.';
                $this->writeHtaccessFile($path, $template, $output, $label);
            } else {
                $output[] = $label.' exists.';
            }

            return;
        }

        if (file_exists($path)) {
            throw new \RuntimeException($label.' exists but is not a file: '.$path);
        }

        $output[] = $label.' missing. Creating.';
        $this->writeHtaccessFile($path, $template, $output, $label);
    }

    /**
     * @param  array<int, string>  $output
     */
    private function writeHtaccessFile(string $path, ?string $template, array &$output, string $label): void
    {
        if ($template === null || trim($template) === '') {
            throw new \RuntimeException('No template available to create '.$label.'.');
        }

        $directory = dirname($path);
        $this->permissionService->ensureWritableDirectory($directory, $output, $label.' directory');
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new \RuntimeException($label.' directory is not writable: '.$directory);
        }

        if (@file_put_contents($path, $template) === false) {
            throw new \RuntimeException('Unable to write '.$label.' at '.$path);
        }

        @chmod($path, 0664);
        $output[] = 'Created '.$label.'.';
    }

    private function rootHtaccessTemplate(string $laravelRoot): ?string
    {
        $candidate = $laravelRoot.DIRECTORY_SEPARATOR.'sample.htaccess';
        $template = $this->readHtaccessTemplate($candidate);
        if ($template !== null) {
            return $template;
        }

        $template = $this->readHtaccessTemplate(base_path('sample.htaccess'));
        if ($template !== null) {
            return $template;
        }

        return $this->defaultRootHtaccessTemplate();
    }

    private function publicHtaccessTemplate(): ?string
    {
        $template = $this->readHtaccessTemplate(base_path('public'.DIRECTORY_SEPARATOR.'.htaccess'));
        if ($template !== null) {
            return $template;
        }

        return $this->defaultPublicHtaccessTemplate();
    }

    private function readHtaccessTemplate(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        return rtrim($contents)."\n";
    }

    private function defaultRootHtaccessTemplate(): string
    {
        return "<IfModule mod_rewrite.c>\n"
            ."    Options +SymLinksIfOwnerMatch\n"
            ."    RewriteEngine On\n"
            ."\n"
            ."    RewriteCond %{REQUEST_URI} !^/public/\n"
            ."\n"
            ."    RewriteCond %{REQUEST_FILENAME} !-d\n"
            ."    RewriteCond %{REQUEST_FILENAME} !-f\n"
            ."\n"
            ."    RewriteRule ^(.*)$ /public/$1\n"
            ."    #RewriteRule ^ index.php [L]\n"
            ."    RewriteRule ^(/)?$ public/index.php [L]\n"
            ."</IfModule>\n";
    }

    private function defaultPublicHtaccessTemplate(): string
    {
        return "<IfModule mod_rewrite.c>\n"
            ."    <IfModule mod_negotiation.c>\n"
            ."        Options -MultiViews -Indexes\n"
            ."    </IfModule>\n"
            ."\n"
            ."    RewriteEngine On\n"
            ."\n"
            ."    # Handle Authorization Header\n"
            ."    RewriteCond %{HTTP:Authorization} .\n"
            ."    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            ."\n"
            ."    # Handle X-XSRF-Token Header\n"
            ."    RewriteCond %{HTTP:x-xsrf-token} .\n"
            ."    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]\n"
            ."\n"
            ."    # Redirect Trailing Slashes If Not A Folder...\n"
            ."    RewriteCond %{REQUEST_FILENAME} !-d\n"
            ."    RewriteCond %{REQUEST_URI} (.+)/$\n"
            ."    RewriteRule ^ %1 [L,R=301]\n"
            ."\n"
            ."    # Send Requests To Front Controller...\n"
            ."    RewriteCond %{REQUEST_FILENAME} !-d\n"
            ."    RewriteCond %{REQUEST_FILENAME} !-f\n"
            ."    RewriteRule ^ index.php [L]\n"
            ."</IfModule>\n";
    }

    /**
     * @param  array<int, string>  $output
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
        if ($this->canRunArtisan($laravelRoot)) {
            if ($this->tryArtisanStorageLink($laravelRoot, $output) && $this->storageLinkIsValid($linkPath, $targetPath)) {
                $output[] = 'Laravel storage link created by artisan.';

                return;
            }
        } else {
            $output[] = 'Skipping php artisan storage:link because vendor/autoload.php is missing.';
        }

        if ($this->storageLinkIsValid($linkPath, $targetPath)) {
            $output[] = 'Laravel storage link exists.';

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

        if (PHP_OS_FAMILY === 'Windows' && file_exists($linkPath)) {
            $linkTarget = @readlink($linkPath);
            $targetReal = realpath($targetPath);
            if ($linkTarget !== false && $targetReal !== false) {
                $resolved = $this->resolveLinkTarget($linkPath, $linkTarget);
                $resolvedReal = $resolved !== null ? realpath($resolved) : false;

                if ($resolvedReal !== false && strcasecmp($resolvedReal, $targetReal) === 0) {
                    return true;
                }
            }
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

    private function canRunArtisan(string $laravelRoot): bool
    {
        return is_file($laravelRoot.DIRECTORY_SEPARATOR.'artisan')
            && is_file($laravelRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php');
    }

    private function tryWindowsJunction(string $linkPath, string $targetPath, array &$output): bool
    {
        $process = new Process([
            'cmd',
            '/c',
            'mklink',
            '/J',
            $this->windowsPath($linkPath),
            $this->windowsPath($targetPath),
        ]);
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

    private function windowsPath(string $path): string
    {
        return str_replace('/', '\\', $path);
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
