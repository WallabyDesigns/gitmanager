<?php

namespace App\Services;

use App\Models\Project;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PermissionService
{
    /**
     * @return array{status: string, message: string, parent?: string}
     */
    public function checkPathPermissions(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [
                'status' => 'missing',
                'message' => 'Enter a local path to run the permission check.',
            ];
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return [
                'status' => 'missing',
                'message' => 'Enter a valid local path to run the permission check.',
            ];
        }

        if (is_dir($path)) {
            if (is_writable($path)) {
                return [
                    'status' => 'ok',
                    'message' => 'Directory exists and is writable.',
                ];
            }

            return $this->permissionStatusForParent($path, true);
        }

        $parent = $this->closestExistingParent($path);
        if (! $parent) {
            return [
                'status' => 'missing_parent',
                'message' => 'No existing parent directory found for this path.',
            ];
        }

        if (is_writable($parent)) {
            return [
                'status' => 'can_create',
                'message' => 'Parent directory is writable. The path can be created by the web user.',
                'parent' => $parent,
            ];
        }

        return $this->permissionStatusForParent($parent, false);
    }

    public function attemptFixPermissions(Project $project, string $path, array &$output, bool $throwOnFailure): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output[] = 'Permission fix skipped on Windows.';
            $project->permissions_locked = false;
            $project->permissions_issue_message = null;
            $project->permissions_checked_at = now();
            $project->save();

            return true;
        }

        $laravelRoot = $this->findLaravelRoot($project->local_path)
            ?? $this->findLaravelRoot($path)
            ?? $path;
        $isLaravel = $this->findLaravelRoot($project->local_path) !== null || $this->findLaravelRoot($path) !== null;
        $needsComposer = $project->run_composer_install || is_file($laravelRoot.DIRECTORY_SEPARATOR.'composer.json');
        $needsNpm = $project->run_npm_install || is_file($laravelRoot.DIRECTORY_SEPARATOR.'package.json');
        $needsBuild = $project->run_build_command || is_dir($laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build');

        $targets = [
            ['label' => 'Project directory', 'path' => $laravelRoot, 'required' => true],
            ['label' => 'Vendor directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'vendor', 'required' => $needsComposer],
            ['label' => 'Storage directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'storage', 'required' => $isLaravel],
            ['label' => 'Bootstrap cache directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache', 'required' => $isLaravel],
            ['label' => 'Build directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build', 'required' => $needsBuild],
            ['label' => 'Build assets directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets', 'required' => $needsBuild],
            ['label' => 'Node modules directory', 'path' => $laravelRoot.DIRECTORY_SEPARATOR.'node_modules', 'required' => $needsNpm],
        ];

        $failures = [];
        foreach ($targets as $target) {
            $targetPath = $target['path'];
            if (! $target['required'] && ! is_dir($targetPath)) {
                continue;
            }

            $this->ensureWritableDirectory($targetPath, $output, $target['label']);
            if (! is_writable($targetPath)) {
                $failures[] = $target['label'].': '.$targetPath;

                continue;
            }

            $output[] = 'Adjusted permissions for: '.$targetPath;
        }

        $project->permissions_checked_at = now();
        if ($failures !== []) {
            $project->permissions_locked = true;
            $project->permissions_issue_message = 'Still not writable: '.implode(' | ', $failures);
            $project->save();

            if ($throwOnFailure) {
                throw new \RuntimeException('Permission fix incomplete. '.implode(' | ', $failures));
            }

            return false;
        }

        $project->permissions_locked = false;
        $project->permissions_issue_message = null;
        $project->save();

        return true;
    }

    public function needsPermissionFix(Project $project, string $executionPath): bool
    {
        if ($project->run_composer_install) {
            $vendorPath = $executionPath.DIRECTORY_SEPARATOR.'vendor';
            if ((is_dir($vendorPath) && ! is_writable($vendorPath)) || (! is_dir($vendorPath) && ! is_writable($executionPath))) {
                return true;
            }
        }

        if ($project->run_npm_install) {
            $modulesPath = $executionPath.DIRECTORY_SEPARATOR.'node_modules';
            if ((is_dir($modulesPath) && ! is_writable($modulesPath)) || (! is_dir($modulesPath) && ! is_writable($executionPath))) {
                return true;
            }
        }

        return false;
    }

    public function isPermissionError(\Throwable $exception, array $output): bool
    {
        $message = $exception->getMessage();
        if ($exception instanceof ProcessFailedException) {
            $process = $exception->getProcess();
            $message .= "\n".$process->getErrorOutput()."\n".$process->getOutput();
        }

        $haystack = strtolower($message."\n".implode("\n", $output));
        $needles = [
            'eacces',
            'eperm',
            'permission denied',
            'access denied',
            'operation was rejected by your operating system',
            'not permitted',
            'could not delete',
            'failed to delete',
            'failed to remove',
            'unlink',
        ];

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function ensureWritableDirectory(string $path, array &$output, string $label): void
    {
        if (! is_dir($path)) {
            if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                $output[] = 'Warning: unable to create '.$label.': '.$path;

                return;
            }
        }

        if (! is_writable($path)) {
            @chmod($path, 0775);
        }

        if (! is_writable($path)) {
            $this->chmodRecursivePath($path, 0775, 0664, $output);
        }

        if (! is_writable($path)) {
            $output[] = 'Warning: '.$label.' is not writable: '.$path.'. Run Fix Permissions or ensure the web user owns this path.';
        }
    }

    public function applyOwnershipFromReference(string $targetPath, string $referencePath, array &$output, string $label): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (! is_dir($targetPath)) {
            return;
        }

        $referencePath = is_dir($referencePath) ? $referencePath : dirname($referencePath);
        $owner = @fileowner($referencePath);
        $group = @filegroup($referencePath);
        if ($owner === false || $group === false) {
            $output[] = 'Warning: unable to read ownership for '.$label.'.';

            return;
        }

        if (function_exists('posix_geteuid')) {
            $euid = posix_geteuid();
            if ($euid !== 0 && $euid !== $owner) {
                $output[] = 'Warning: unable to change ownership for '.$label.' (not running as root).';

                return;
            }
        }

        $this->chownRecursivePath($targetPath, (int) $owner, (int) $group);
        $output[] = 'Aligned ownership for '.$label.' with project directory.';
    }

    public function attemptPrivilegedPathCreate(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $parent = $this->closestExistingParent($path);
        if (! $parent) {
            return false;
        }

        $owner = @fileowner($parent);
        $group = @filegroup($parent);
        if ($owner === false || $group === false) {
            return false;
        }

        if ($this->runningAsRoot()) {
            if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                return false;
            }

            if (! $this->applyOwnership($path, (int) $owner, (int) $group)) {
                return false;
            }

            return true;
        }

        if (! $this->sudoEnabled()) {
            return false;
        }

        $sudo = $this->sudoBinary();
        if ($sudo === '') {
            return false;
        }

        if (! $this->runSilentProcess([$sudo, '-n', 'mkdir', '-p', $path])) {
            return false;
        }

        $chownTarget = $owner.':'.$group;
        if (! $this->runSilentProcess([$sudo, '-n', 'chown', '-R', $chownTarget, $path])) {
            return false;
        }

        return $this->verifyOwnership($path, (int) $owner, (int) $group);
    }

    public function closestExistingParent(string $path): ?string
    {
        $cursor = rtrim($path, DIRECTORY_SEPARATOR);
        if ($cursor === '') {
            return null;
        }

        while (! is_dir($cursor)) {
            $parent = dirname($cursor);
            if ($parent === '' || $parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        return is_dir($cursor) ? $cursor : null;
    }

    private function chmodRecursivePath(string $path, int $dirMode, int $fileMode, array &$output): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $target = $item->getPathname();
            if ($item->isDir()) {
                @chmod($target, $dirMode);
            } else {
                @chmod($target, $fileMode);
            }
        }

        @chmod($path, $dirMode);
    }

    public function chownRecursivePath(string $path, int $owner, int $group): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $target = $item->getPathname();
            @chown($target, $owner);
            @chgrp($target, $group);
        }

        @chown($path, $owner);
        @chgrp($path, $group);
    }

    private function applyOwnership(string $path, int $owner, int $group): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        if (! $this->runningAsRoot()) {
            return false;
        }

        $this->chownRecursivePath($path, $owner, $group);

        return $this->verifyOwnership($path, $owner, $group);
    }

    private function verifyOwnership(string $path, int $owner, int $group): bool
    {
        $currentOwner = @fileowner($path);
        $currentGroup = @filegroup($path);
        if ($currentOwner === false || $currentGroup === false) {
            return false;
        }

        return $currentOwner === $owner && $currentGroup === $group;
    }

    private function permissionStatusForParent(string $parent, bool $pathExists): array
    {
        $hasPrivilege = $this->runningAsRoot() || $this->sudoEnabled();
        $status = $hasPrivilege ? 'needs_privilege' : 'not_writable';

        if ($hasPrivilege) {
            $message = $pathExists
                ? 'Directory is not writable by the web user. Privileged creation will be used to adjust ownership.'
                : 'Parent directory is not writable. Privileged creation will be used to create and chown this path.';
        } else {
            $message = $pathExists
                ? 'Directory exists but is not writable by the web user.'
                : 'Parent directory is not writable by the web user. Enable GWM_SUDO_ENABLED or adjust ownership.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'parent' => $parent,
        ];
    }

    private function runningAsRoot(): bool
    {
        if (! function_exists('posix_geteuid')) {
            return false;
        }

        return posix_geteuid() === 0;
    }

    private function sudoEnabled(): bool
    {
        return (bool) config('gitmanager.sudo.enabled', false);
    }

    private function sudoBinary(): string
    {
        $binary = trim((string) config('gitmanager.sudo.binary', 'sudo'));
        $binary = trim($binary, "\"' ");

        return $binary;
    }

    private function runSilentProcess(array $command): bool
    {
        try {
            $process = new Process($command);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable $exception) {
            return false;
        }
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
}
