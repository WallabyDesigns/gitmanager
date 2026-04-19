<?php

namespace App\Services;

use App\Models\AppUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SelfUpdateService
{
    private const REPO_URL = 'https://github.com/wallabydesigns/gitmanager.git';
    private const DEFAULT_BRANCH = 'main';
    private const STATUS_CACHE_KEY = 'gwm_self_update_status';
    private const DEFAULT_ENTERPRISE_PACKAGE = 'wallabydesigns/gitmanager-enterprise';

    /**
     * @return array{
     *   status: string,
     *   core_status?: string,
     *   current?: string|null,
     *   latest?: string|null,
     *   branch?: string|null,
     *   checked_at?: string|null,
     *   error?: string|null,
     *   enterprise_update_available?: bool,
     *   enterprise_package?: array<string, mixed>
     * }
     */
    public function getUpdateStatus(bool $force = false): array
    {
        if (! $force) {
            $cached = Cache::get(self::STATUS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $status = $this->computeUpdateStatus();
        Cache::put(self::STATUS_CACHE_KEY, $status, now()->addMinutes(5));

        return $status;
    }

    /**
     * @return array<int, array{hash: string, subject: string}>
     */
    public function getPendingChanges(?string $fromHash, ?string $toHash, int $limit = 50): array
    {
        $from = trim((string) $fromHash);
        $to = trim((string) $toHash);

        if ($from === '' || $to === '' || $from === $to) {
            return [];
        }

        $repoPath = base_path();
        $output = [];

        try {
            $this->ensureGitRepository($repoPath);

            $process = $this->runProcess(
                ['git', '-C', $repoPath, 'log', '--no-decorate', '--pretty=format:%h:::%s', '--max-count='.$limit, $from.'..'.$to],
                $output,
                null,
                false
            );

            if (! $process->isSuccessful()) {
                return [];
            }

            $raw = trim($process->getOutput());
            if ($raw === '') {
                return [];
            }

            $lines = array_values(array_filter(explode("\n", $raw)));
            $changes = [];

            foreach ($lines as $line) {
                [$hash, $subject] = array_pad(explode(':::', $line, 2), 2, '');
                $hash = trim($hash);
                $subject = trim($subject);

                if ($hash === '' && $subject === '') {
                    continue;
                }

                $changes[] = [
                    'hash' => $hash,
                    'subject' => $subject,
                ];
            }

            return $changes;
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * @return array<int, array{hash: string, subject: string}>
     */
    public function getPendingChangesPreview(int $limit = 50): array
    {
        $repoPath = base_path();
        $output = [];

        try {
            $this->ensureGitRepository($repoPath);
            $this->assertGitWritable($repoPath, $output, false, false);
            $this->ensureOriginRemote($repoPath, $output);

            $branch = $this->resolveBranch($repoPath, $output);
            $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output, null, false);

            $current = $this->tryRevParse($repoPath);
            $latest = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'origin/'.$branch], $output, null, false)->getOutput());

            return $this->getPendingChanges($current, $latest, $limit);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    public function getCurrentVersionHash(): ?string
    {
        $repoPath = base_path();
        $output = [];

        try {
            $this->ensureGitRepository($repoPath);
            $this->assertGitWritable($repoPath, $output, false, false);

            return $this->tryRevParse($repoPath);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function updateSmart(?User $user = null): AppUpdate
    {
        try {
            $repoPath = base_path();
            $statusOutput = [];
            $status = $this->getWorkingTreeStatus($repoPath, $statusOutput);
            $hasHead = $this->tryRevParse($repoPath);

            if (! $hasHead || $status !== '') {
                return $this->update($user, true);
            }
        } catch (\Throwable $exception) {
            // Fall through to standard update if we cannot inspect status.
        }

        return $this->update($user, false);
    }

    public function rollback(?User $user = null, ?string $targetHash = null): AppUpdate
    {
        $repoPath = base_path();
        Cache::forget(self::STATUS_CACHE_KEY);

        $update = AppUpdate::create([
            'triggered_by' => $user?->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $output = [];
        $fromHash = null;
        $toHash = null;
        $stashed = false;
        $stashRestoreFailed = false;
        $backupPath = null;
        $htaccessSnapshots = [];
        $protectedHtaccess = null;
        $untrackedBackups = [];
        $untrackedFiles = [];

        try {
            $output[] = 'Rollback path: '.$repoPath;
            $target = $this->resolveRollbackTarget($targetHash, $output);
            if (! $target) {
                $update->status = 'failed';
                $update->output_log = implode("\n", $output);
                $update->finished_at = now();
                $update->save();

                return $update;
            }

            $protectedHtaccess = $this->prepareProtectedHtaccess($repoPath, $output);
            $htaccessSnapshots = $this->snapshotFiles($repoPath, ['.htaccess']);
            $this->ensureGitRepository($repoPath);
            $this->assertGitWritable($repoPath, $output);

            $fromHash = $this->tryRevParse($repoPath);
            if (! $fromHash) {
                throw new \RuntimeException('Repository has no commits. Rollback unavailable.');
            }

            $untrackedFiles = $this->getUntrackedFiles($repoPath, $output);
            if ($untrackedFiles !== []) {
                $backup = $this->backupUntrackedFiles($repoPath, $untrackedFiles, $output);
                if ($backup) {
                    $untrackedBackups[] = $backup;
                }
                $this->removeUntrackedFiles($repoPath, $untrackedFiles, $output);
            }
            $stashed = $this->stashIfDirty($repoPath, $output, $htaccessSnapshots);
            if (! $fromHash) {
                $includeDependencies = $this->shouldIncludeDependencyDirs($repoPath, $output);
                $backupPath = $this->backupWorkingTree($repoPath, $output, $includeDependencies);
            }

            $this->protectHtaccess($repoPath, $output);
            $output[] = 'Rolling back to '.$target.'.';
            $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $target], $output);
            $this->runGitClean($repoPath, $this->forceCleanExcludePaths(), $output);
            $toHash = $this->tryRevParse($repoPath);

            $postAttempts = 0;
            while (true) {
                try {
                    $this->runPostUpdateTasks($repoPath, $fromHash, $toHash, $output);
                    break;
                } catch (\Throwable $exception) {
                    $postAttempts++;
                    if ($postAttempts >= 2) {
                        throw $exception;
                    }
                    $output[] = 'Post-rollback tasks failed. Retrying once.';
                    $this->applyPostUpdatePermissions($repoPath, $output);
                }
            }

            if ($stashed) {
                $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                if (! $pop->isSuccessful()) {
                    $stashRestoreFailed = true;
                    $output[] = 'Warning: rollback applied, but stashed changes could not be restored. Run `git stash list` to review.';
                }
            }

            $this->restoreUntrackedBackups($repoPath, $untrackedBackups, $output);
            if ($backupPath) {
                $output[] = 'Backup created at: '.$backupPath;
            }

            $this->restoreSnapshots($repoPath, $htaccessSnapshots, $output);
            $this->restoreProtectedHtaccess($repoPath, $protectedHtaccess, $output);
            $this->removeExcludedPaths($repoPath, $output);

            $update->status = 'success';
            $update->from_hash = $fromHash;
            $update->to_hash = $toHash ?: $target;
            $update->output_log = implode("\n", $output);
            $update->finished_at = now();
            $update->save();
            Cache::forget(self::STATUS_CACHE_KEY);

            return $update;
        } catch (\Throwable $exception) {
            if ($fromHash && ! $stashRestoreFailed) {
                $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
            }

            if ($stashed && ! $stashRestoreFailed) {
                $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                if (! $pop->isSuccessful()) {
                    $output[] = 'Stash restore failed after rollback failure. Run `git stash pop` manually if needed.';
                }
            }

            $this->restoreUntrackedBackups($repoPath, $untrackedBackups, $output);
            $this->restoreSnapshots($repoPath, $htaccessSnapshots, $output);
            $this->restoreProtectedHtaccess($repoPath, $protectedHtaccess, $output);
            $this->removeExcludedPaths($repoPath, $output);

            $update->status = 'failed';
            $update->from_hash = $fromHash;
            $update->to_hash = $toHash;
            $update->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
            $update->finished_at = now();
            $update->save();
            Cache::forget(self::STATUS_CACHE_KEY);

            return $update;
        }
    }

    public function forceUpdate(?User $user = null): AppUpdate
    {
        return $this->update($user, true, true);
    }

    public function update(?User $user = null, bool $allowDirty = false, bool $force = false): AppUpdate
    {
        $repoPath = base_path();
        Cache::forget(self::STATUS_CACHE_KEY);

        $update = AppUpdate::create([
            'triggered_by' => $user?->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $output = [];
        $forceUpdate = $force;
        if ($forceUpdate) {
            $allowDirty = true;
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $fromHash = null;
            $toHash = null;
            $remoteHash = null;
            $branch = null;
            $stashed = false;
            $backupPath = null;
            $stashRestoreFailed = false;
            $htaccessSnapshots = [];
            $protectedHtaccess = null;
            $untrackedBackups = [];
            $untrackedFiles = [];

            try {
                $output[] = 'Update path: '.$repoPath;
                $protectedHtaccess = $this->prepareProtectedHtaccess($repoPath, $output);
                $htaccessSnapshots = $this->snapshotFiles($repoPath, ['.htaccess']);
                $this->ensureGitRepository($repoPath);
                $this->assertGitWritable($repoPath, $output);
                $this->ensureOriginRemote($repoPath, $output);
                $fromHash = $this->tryRevParse($repoPath);
                if ($forceUpdate) {
                    $output[] = 'Force update requested; local changes will be discarded (protected files are preserved).';
                } elseif ($allowDirty) {
                    $untrackedFiles = $this->getUntrackedFiles($repoPath, $output);
                    if ($untrackedFiles !== []) {
                        $backup = $this->backupUntrackedFiles($repoPath, $untrackedFiles, $output);
                        if ($backup) {
                            $untrackedBackups[] = $backup;
                        }
                        $this->removeUntrackedFiles($repoPath, $untrackedFiles, $output);
                    }
                    $stashed = $this->stashIfDirty($repoPath, $output, $htaccessSnapshots);
                    if (! $fromHash) {
                        $includeDependencies = $this->shouldIncludeDependencyDirs($repoPath, $output);
                        $backupPath = $this->backupWorkingTree($repoPath, $output, $includeDependencies);
                    }
                } else {
                    $this->ensureCleanWorkingTree($repoPath, $output);
                    if (! $fromHash) {
                        throw new \RuntimeException('Repository has no commits. Use preserve update or initialize git before updating.');
                    }
                }

                $branch = $this->resolveBranch($repoPath, $output);

                $this->protectHtaccess($repoPath, $output);
                $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);
                $remoteHash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'origin/'.$branch], $output)->getOutput());

                if ($fromHash === $remoteHash) {
                    $enterprisePackage = $this->getEnterprisePackageUpdateStatus($repoPath, $output);
                    if (($enterprisePackage['status'] ?? '') === 'update-available') {
                        $output[] = 'Enterprise package update available: '.($enterprisePackage['current'] ?? 'unknown').' → '.($enterprisePackage['latest'] ?? 'unknown').'.';
                        $update->status = 'warning';
                    } else {
                        $output[] = $enterprisePackage['message'] ?? 'Enterprise package update check completed.';
                        $update->status = 'skipped';
                    }
                    $update->from_hash = $fromHash;
                    $update->to_hash = $fromHash;
                    $update->output_log = implode("\n", $output);
                    $update->finished_at = now();
                    $update->save();

                    return $update;
                }

                if ($forceUpdate) {
                    $output[] = 'Force updating working tree to origin/'.$branch.'.';
                    $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', 'origin/'.$branch], $output);
                    $this->runGitClean($repoPath, $this->forceCleanExcludePaths(), $output);
                } elseif ($fromHash) {
                    $this->mergeFastForward($repoPath, $branch, $output, $untrackedBackups);
                } else {
                    if ($backupPath) {
                        $output[] = 'No initial commit detected; applying update with a forced checkout.';
                    }
                    $command = ['git', '-C', $repoPath, 'checkout', '-B', $branch, 'origin/'.$branch];
                    if ($backupPath) {
                        $command[] = '--force';
                    }
                    $this->runProcess($command, $output);
                }
                $toHash = $this->tryRevParse($repoPath);

                $postAttempts = 0;
                while (true) {
                    try {
                        $this->runPostUpdateTasks($repoPath, $fromHash, $toHash, $output);
                        break;
                    } catch (\Throwable $exception) {
                        $postAttempts++;
                        if ($postAttempts >= 2) {
                            throw $exception;
                        }
                        $output[] = 'Post-update tasks failed. Retrying once.';
                        $this->applyPostUpdatePermissions($repoPath, $output);
                    }
                }

                if ($stashed) {
                    $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                    if (! $pop->isSuccessful()) {
                        $stashRestoreFailed = true;
                        $output[] = 'Warning: update applied, but stashed changes could not be restored. Run `git stash list` to review.';
                    }
                }
                $this->restoreUntrackedBackups($repoPath, $untrackedBackups, $output);
                if ($backupPath) {
                    $output[] = 'Backup created at: '.$backupPath;
                }

                $this->restoreSnapshots($repoPath, $htaccessSnapshots, $output);
                $this->restoreProtectedHtaccess($repoPath, $protectedHtaccess, $output);
                $this->removeExcludedPaths($repoPath, $output);

                $update->status = 'success';
                $update->from_hash = $fromHash;
                $update->to_hash = $toHash ?: $remoteHash;
                $update->output_log = implode("\n", $output);
                $update->finished_at = now();
                $update->save();
                Cache::forget(self::STATUS_CACHE_KEY);

                return $update;
            } catch (\Throwable $exception) {
                if ($fromHash && ! $stashRestoreFailed) {
                    $this->runProcess(['git', '-C', $repoPath, 'reset', '--hard', $fromHash], $output, null, false);
                }

                if ($stashed && ! $stashRestoreFailed) {
                    $pop = $this->runProcess(['git', '-C', $repoPath, 'stash', 'pop'], $output, null, false);
                    if (! $pop->isSuccessful()) {
                        $output[] = 'Stash restore failed after update failure. Run `git stash pop` manually if needed.';
                    }
                }
                $this->restoreUntrackedBackups($repoPath, $untrackedBackups, $output);

                $this->restoreSnapshots($repoPath, $htaccessSnapshots, $output);
                $this->restoreProtectedHtaccess($repoPath, $protectedHtaccess, $output);
                $this->removeExcludedPaths($repoPath, $output);

                if ($attempt < 2) {
                    $output[] = 'Update failed. Retrying once.';
                    continue;
                }

                $status = 'failed';
                if ($toHash && $remoteHash && $toHash === $remoteHash) {
                    $status = 'warning';
                    $output[] = 'Update applied, but post-update tasks failed. Review the log and retry if needed.';
                }

                $update->status = $status;
                $update->from_hash = $fromHash;
                $update->to_hash = $toHash;
                $update->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
                $update->finished_at = now();
                $update->save();
                Cache::forget(self::STATUS_CACHE_KEY);

                return $update;
            }
        }

        return $update;
    }

    /**
     * @return array{
     *   status: string,
     *   core_status?: string,
     *   current?: string|null,
     *   latest?: string|null,
     *   branch?: string|null,
     *   checked_at?: string|null,
     *   error?: string|null,
     *   enterprise_update_available?: bool,
     *   enterprise_package?: array<string, mixed>
     * }
     */
    private function computeUpdateStatus(): array
    {
        $repoPath = base_path();
        $output = [];
        $branch = null;
        $enterprisePackage = $this->defaultEnterprisePackageStatus('unknown', 'Enterprise package update checks are unavailable.');

        try {
            $this->ensureGitRepository($repoPath);
            $this->assertGitWritable($repoPath, $output, false, false);
            $this->ensureOriginRemote($repoPath, $output);
            $branch = $this->resolveBranch($repoPath, $output);
            $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);

            $current = $this->tryRevParse($repoPath);
            $latest = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', 'origin/'.$branch], $output)->getOutput());
            $enterprisePackage = $this->getEnterprisePackageUpdateStatus($repoPath, $output);
            $enterpriseUpdateAvailable = ($enterprisePackage['status'] ?? '') === 'update-available';

            if (! $current || $latest === '') {
                return [
                    'status' => $enterpriseUpdateAvailable ? 'update-available' : 'unknown',
                    'core_status' => 'unknown',
                    'current' => $current,
                    'latest' => $latest !== '' ? $latest : null,
                    'branch' => $branch,
                    'checked_at' => now()->toDateTimeString(),
                    'enterprise_update_available' => $enterpriseUpdateAvailable,
                    'enterprise_package' => $enterprisePackage,
                ];
            }

            $coreStatus = $current === $latest ? 'up-to-date' : 'update-available';

            return [
                'status' => ($coreStatus === 'update-available' || $enterpriseUpdateAvailable) ? 'update-available' : 'up-to-date',
                'core_status' => $coreStatus,
                'current' => $current,
                'latest' => $latest,
                'branch' => $branch,
                'checked_at' => now()->toDateTimeString(),
                'enterprise_update_available' => $enterpriseUpdateAvailable,
                'enterprise_package' => $enterprisePackage,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => ($enterprisePackage['status'] ?? '') === 'update-available' ? 'update-available' : 'unknown',
                'core_status' => 'unknown',
                'branch' => $branch,
                'checked_at' => now()->toDateTimeString(),
                'error' => $exception->getMessage(),
                'enterprise_update_available' => ($enterprisePackage['status'] ?? '') === 'update-available',
                'enterprise_package' => $enterprisePackage,
            ];
        }
    }

    /**
     * @return array{
     *   name: string,
     *   required: bool,
     *   installed: bool,
     *   current: ?string,
     *   latest: ?string,
     *   status: string,
     *   message: string
     * }
     */
    private function getEnterprisePackageUpdateStatus(string $repoPath, array &$output): array
    {
        $packageName = $this->enterprisePackageName();
        if (! (bool) config('gitmanager.enterprise.check_updates', true)) {
            return $this->defaultEnterprisePackageStatus('disabled', 'Enterprise package update checks are disabled.', $packageName);
        }

        if ($packageName === '') {
            return $this->defaultEnterprisePackageStatus('unknown', 'Enterprise package name is not configured.', $packageName);
        }

        $required = $this->composerRequiresPackage($repoPath, $packageName);
        if (! $required && ! class_exists(\GitManagerEnterprise\EnterpriseServiceProvider::class)) {
            return $this->defaultEnterprisePackageStatus('not-installed', 'Enterprise package is not installed on this panel.', $packageName, false, false);
        }

        if (! $this->binaryAvailable($this->composerBinary())) {
            return $this->defaultEnterprisePackageStatus('unknown', 'Composer binary not available; unable to check enterprise package updates.', $packageName, $required, false);
        }

        $process = $this->runProcess(
            ['composer', 'show', $packageName, '--latest', '--format=json'],
            $output,
            $repoPath,
            false
        );
        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ($error === '') {
                $error = trim($process->getOutput());
            }

            return $this->defaultEnterprisePackageStatus(
                'unknown',
                'Unable to determine enterprise package update status.'.($error !== '' ? ' '.$error : ''),
                $packageName,
                $required,
                false
            );
        }

        $payload = json_decode((string) $process->getOutput(), true);
        if (! is_array($payload)) {
            return $this->defaultEnterprisePackageStatus('unknown', 'Invalid Composer response while checking enterprise package updates.', $packageName, $required, true);
        }

        $versions = $payload['versions'] ?? [];
        $current = null;
        if (is_array($versions)) {
            foreach ($versions as $candidate) {
                if (! is_string($candidate)) {
                    continue;
                }
                $candidate = trim($candidate);
                if ($candidate === '' || $candidate === 'dev-main' || str_starts_with($candidate, '9999999-dev')) {
                    continue;
                }
                $current = $candidate;
                break;
            }
        }
        if ($current === null && is_string($payload['version'] ?? null)) {
            $current = trim((string) $payload['version']);
        }

        $latest = is_string($payload['latest'] ?? null)
            ? trim((string) $payload['latest'])
            : null;
        if ($latest === '') {
            $latest = null;
        }

        if (($current === null || $current === '') && ($latest === null || $latest === '')) {
            return $this->defaultEnterprisePackageStatus('unknown', 'Enterprise package is installed but version details are unavailable.', $packageName, $required, true);
        }

        $current = $current !== null && $current !== '' ? $current : null;
        $latest = $latest !== null && $latest !== '' ? $latest : $current;

        $updateAvailable = false;
        if ($current !== null && $latest !== null) {
            $normalizedCurrent = ltrim($current, 'vV');
            $normalizedLatest = ltrim($latest, 'vV');

            if (preg_match('/^\d+(\.\d+){0,3}([\-+].*)?$/', $normalizedCurrent) === 1
                && preg_match('/^\d+(\.\d+){0,3}([\-+].*)?$/', $normalizedLatest) === 1) {
                $updateAvailable = version_compare($normalizedCurrent, $normalizedLatest, '<');
            } else {
                $updateAvailable = ! hash_equals($current, $latest);
            }
        }

        return [
            'name' => $packageName,
            'required' => $required,
            'installed' => true,
            'current' => $current,
            'latest' => $latest,
            'status' => $updateAvailable ? 'update-available' : 'up-to-date',
            'message' => $updateAvailable
                ? 'Enterprise package update is available.'
                : 'Enterprise package is up to date.',
        ];
    }

    /**
     * @return array{
     *   name: string,
     *   required: bool,
     *   installed: bool,
     *   current: ?string,
     *   latest: ?string,
     *   status: string,
     *   message: string
     * }
     */
    private function defaultEnterprisePackageStatus(
        string $status,
        string $message,
        ?string $packageName = null,
        bool $required = false,
        bool $installed = false
    ): array {
        return [
            'name' => $packageName ?? $this->enterprisePackageName(),
            'required' => $required,
            'installed' => $installed,
            'current' => null,
            'latest' => null,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function enterprisePackageName(): string
    {
        $configured = trim((string) config('gitmanager.enterprise.package_name', self::DEFAULT_ENTERPRISE_PACKAGE));

        return $configured !== '' ? $configured : self::DEFAULT_ENTERPRISE_PACKAGE;
    }

    private function composerRequiresPackage(string $repoPath, string $packageName): bool
    {
        $composerFile = $repoPath.DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($composerFile)) {
            return false;
        }

        $json = json_decode((string) file_get_contents($composerFile), true);
        if (! is_array($json)) {
            return false;
        }

        $requires = $json['require'] ?? [];
        $requiresDev = $json['require-dev'] ?? [];

        return (is_array($requires) && array_key_exists($packageName, $requires))
            || (is_array($requiresDev) && array_key_exists($packageName, $requiresDev));
    }

    private function resolveRollbackTarget(?string $targetHash, array &$output): ?string
    {
        $repoPath = base_path();
        $targetHash = trim((string) $targetHash);

        if ($targetHash !== '') {
            $output[] = 'Rollback target requested: '.$targetHash;
            if (! $this->isValidRollbackTarget($repoPath, $targetHash)) {
                $output[] = 'Rollback target not found in repository.';
                return null;
            }

            return $targetHash;
        }

        $latest = AppUpdate::query()
            ->whereNotNull('from_hash')
            ->whereNotNull('to_hash')
            ->where('from_hash', '!=', '')
            ->where('to_hash', '!=', '')
            ->where('status', '!=', 'running')
            ->orderByDesc('started_at')
            ->first();

        if (! $latest) {
            $output[] = 'No previous update found for rollback. Use /update to apply the latest release.';
            return null;
        }

        $target = $latest->from_hash;
        $output[] = 'Using last update rollback target: '.$latest->from_hash.' (from '.$latest->from_hash.' → '.$latest->to_hash.').';

        if (! $this->isValidRollbackTarget($repoPath, $target)) {
            $output[] = 'Rollback target not found in repository.';
            return null;
        }

        return $target;
    }

    private function isValidRollbackTarget(string $repoPath, string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }

        $localOutput = [];
        $process = $this->runProcess(
            ['git', '-C', $repoPath, 'rev-parse', '--verify', $target.'^{commit}'],
            $localOutput,
            null,
            false
        );

        return $process->isSuccessful();
    }

    private function assertGitWritable(string $repoPath, array &$output, bool $logIdentity = true, bool $attemptFix = true): void
    {
        $gitDir = $repoPath.DIRECTORY_SEPARATOR.'.git';
        if (! is_dir($gitDir)) {
            return;
        }

        $currentUid = $this->currentUid();
        $currentUser = $this->resolveUsername($currentUid);

        if ($logIdentity && $currentUid !== null) {
            $output[] = sprintf('Running update as %s (%s).', $currentUser ?? 'unknown', $currentUid);
        }

        $blocked = [];
        $objects = $gitDir.DIRECTORY_SEPARATOR.'objects';
        $index = $gitDir.DIRECTORY_SEPARATOR.'index';

        $owners = [];
        if (is_dir($objects)) {
            $owners[] = ['path' => $objects, 'uid' => fileowner($objects)];
        }
        if (is_file($index)) {
            $owners[] = ['path' => $index, 'uid' => fileowner($index)];
        }

        foreach ($owners as $info) {
            if ($currentUid !== null && $info['uid'] !== false && $info['uid'] !== $currentUid) {
                $ownerName = $this->resolveUsername($info['uid']);
                $output[] = sprintf(
                    'Git ownership mismatch: %s is owned by %s, running as %s.',
                    $info['path'],
                    $ownerName ?? (string) $info['uid'],
                    $currentUser ?? ($currentUid !== null ? (string) $currentUid : 'unknown')
                );
            }
        }

        $blocked = $this->findGitBlockedPaths($gitDir);

        if ($blocked !== [] && $attemptFix) {
            $this->attemptGitPermissionFix($gitDir, $blocked, $output);
            $blocked = $this->findGitBlockedPaths($gitDir);
        }

        if ($blocked === []) {
            return;
        }

        $output[] = 'Git repository is not writable: '.implode(', ', $blocked);
        throw new \RuntimeException('Git repository is not writable by the web server user. Fix ownership/permissions for .git/objects and .git/index, and avoid running updates as a different user.');
    }

    /**
     * @return array<int, string>
     */
    private function findGitBlockedPaths(string $gitDir): array
    {
        $blocked = [];
        $objects = $gitDir.DIRECTORY_SEPARATOR.'objects';
        $index = $gitDir.DIRECTORY_SEPARATOR.'index';

        if (is_dir($objects) && ! is_writable($objects)) {
            $blocked[] = $objects;
        }

        if (is_file($index) && ! is_writable($index)) {
            $blocked[] = $index;
        }

        return $blocked;
    }

    /**
     * @param array<int, string> $blocked
     */
    private function attemptGitPermissionFix(string $gitDir, array $blocked, array &$output): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $output[] = 'Attempting to make git directory writable before update.';

        $objects = $gitDir.DIRECTORY_SEPARATOR.'objects';
        $index = $gitDir.DIRECTORY_SEPARATOR.'index';

        if (in_array($objects, $blocked, true)) {
            $this->chmodRecursiveWithModes($objects, 0775, 0664, $output);
        }

        if (in_array($index, $blocked, true) && is_file($index)) {
            if (@chmod($index, 0664)) {
                $output[] = 'Adjusted permissions on '.$index.'.';
            } else {
                $output[] = 'Unable to adjust permissions on '.$index.'.';
            }
        }
    }

    private function chmodRecursiveWithModes(string $path, int $dirMode, int $fileMode, array &$output): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        @chmod($path, $dirMode);

        foreach ($iterator as $item) {
            $target = $item->getPathname();
            if ($item->isDir()) {
                @chmod($target, $dirMode);
            } else {
                @chmod($target, $fileMode);
            }
        }
    }

    private function currentUid(): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        return null;
    }

    private function resolveUsername(?int $uid): ?string
    {
        if ($uid === null || $uid === false) {
            return null;
        }

        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info) && isset($info['name'])) {
                return $info['name'];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function forceCleanExcludePaths(): array
    {
        $paths = [
            '.env',
            'storage',
            '.htaccess',
            'public'.DIRECTORY_SEPARATOR.'.htaccess',
        ];

        foreach ($this->selfUpdateExcludedPaths() as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<int, string> $excludePaths
     */
    private function runGitClean(string $repoPath, array $excludePaths, array &$output): void
    {
        $command = ['git', '-C', $repoPath, 'clean', '-fd'];
        foreach ($excludePaths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $command[] = '-e';
            $command[] = $path;
        }

        $this->runProcess($command, $output);
    }

    /**
     * @param array<int, string> $relatives
     * @return array<int, array{relative: string, content: string, mode: int}>
     */
    private function snapshotFiles(string $repoPath, array $relatives): array
    {
        $snapshots = [];
        foreach ($relatives as $relative) {
            $relative = ltrim($relative, '/\\');
            $path = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (! is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $snapshots[] = [
                'relative' => $relative,
                'content' => $content,
                'mode' => fileperms($path) & 0777,
            ];
        }

        return $snapshots;
    }

    /**
     * @param array<int, array{relative: string, content: string, mode: int}> $snapshots
     */
    private function restoreSnapshots(string $repoPath, array $snapshots, array &$output): void
    {
        foreach ($snapshots as $snapshot) {
            $target = $repoPath.DIRECTORY_SEPARATOR.$snapshot['relative'];
            $dir = dirname($target);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $written = @file_put_contents($target, $snapshot['content']);
            if ($written === false) {
                $output[] = 'Warning: unable to restore '.$snapshot['relative'].'.';
                continue;
            }

            @chmod($target, $snapshot['mode']);
            $output[] = 'Restored '.$snapshot['relative'].'.';
        }
    }

    private function protectHtaccess(string $repoPath, array &$output): void
    {
        $paths = ['.htaccess'];
        foreach ($paths as $path) {
            $full = $repoPath.DIRECTORY_SEPARATOR.$path;
            if (! file_exists($full)) {
                continue;
            }

            if (! $this->isPathTracked($repoPath, $path)) {
                continue;
            }

            $process = $this->runProcess(['git', '-C', $repoPath, 'update-index', '--skip-worktree', $path], $output, null, false);
            if ($process->isSuccessful()) {
                $output[] = 'Protecting '.$path.' from git updates.';
            }
        }
    }

    private function prepareProtectedHtaccess(string $repoPath, array &$output): ?array
    {
        $relative = '.htaccess';
        $source = $repoPath.DIRECTORY_SEPARATOR.$relative;
        $backupDir = storage_path('app/protected-files');
        $backupPath = $backupDir.DIRECTORY_SEPARATOR.'htaccess.root';
        $metaPath = $backupPath.'.json';

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        if (is_file($source)) {
            $content = file_get_contents($source);
            if ($content !== false) {
                file_put_contents($backupPath, $content);
                file_put_contents($metaPath, json_encode([
                    'relative' => $relative,
                    'mode' => fileperms($source) & 0777,
                ]));
                $output[] = 'Backed up .htaccess before update.';
            }
        }

        if (! is_file($backupPath)) {
            return null;
        }

        $meta = [];
        if (is_file($metaPath)) {
            $decoded = json_decode(file_get_contents($metaPath), true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        return [
            'relative' => $meta['relative'] ?? $relative,
            'mode' => (int) ($meta['mode'] ?? 0644),
            'backup' => $backupPath,
        ];
    }

    private function restoreProtectedHtaccess(string $repoPath, ?array $backup, array &$output): void
    {
        if (! $backup || ! is_file($backup['backup'] ?? '')) {
            return;
        }

        $target = $repoPath.DIRECTORY_SEPARATOR.$backup['relative'];
        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $content = file_get_contents($backup['backup']);
        if ($content === false) {
            return;
        }

        file_put_contents($target, $content);
        @chmod($target, $backup['mode'] ?? 0644);
        $output[] = 'Restored protected .htaccess.';
    }

    private function applyPostUpdatePermissions(string $repoPath, array &$output): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $paths = [
            'storage',
            'bootstrap/cache',
            'node_modules',
        ];

        foreach ($paths as $path) {
            $full = $repoPath.DIRECTORY_SEPARATOR.$path;
            if (! is_dir($full)) {
                continue;
            }

            $process = $this->runProcess(['chmod', '-R', 'ug+rwX', $path], $output, $repoPath, false);
            if (! $process->isSuccessful()) {
                $output[] = 'Warning: unable to chmod '.$path.'.';
            }
        }
    }

    private function runPostUpdateTasks(string $repoPath, ?string $fromHash, ?string $toHash, array &$output): void
    {
        if (is_file(base_path('composer.json'))) {
            $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], $output, $repoPath);
        }

        if (is_file(base_path('package.json'))) {
            $this->applyPostUpdatePermissions($repoPath, $output);
            if ($this->canRunNpm($output)) {
                try {
                    $shouldInstall = $this->shouldRunNpmInstall($repoPath, $fromHash, $toHash, $output);
                    if ($shouldInstall) {
                        $this->runProcess($this->npmInstallCommand($repoPath), $output, $repoPath);
                    } else {
                        $output[] = 'Skipping npm install: no package changes detected.';
                    }

                    if ($this->npmScriptExists('build')) {
                        $manifestBackup = $this->backupBuildManifest($repoPath, $output);
                        if ($this->shouldRunNpmBuild($repoPath, $fromHash, $toHash, $output)) {
                            $this->prepareViteBuildDirectory($repoPath, $output);
                            try {
                                $this->runProcess(['npm', 'run', 'build'], $output, $repoPath);
                            } catch (\Throwable $buildException) {
                                if ($this->isBuildPermissionError($buildException)) {
                                    $output[] = 'Build failed due to permissions; retrying after fixing build directory and node_modules.';
                                    $this->applyPostUpdatePermissions($repoPath, $output);
                                    $this->prepareViteBuildDirectory($repoPath, $output, true);
                                    try {
                                        $this->runProcess(['npm', 'run', 'build'], $output, $repoPath);
                                    } catch (\Throwable $retryException) {
                                        if ($this->isBuildPermissionError($retryException)) {
                                            $output[] = 'Build still failing due to permissions. Skipping build to allow update to complete.';
                                            $this->restoreBuildManifest($repoPath, $manifestBackup, $output);
                                        } else {
                                            throw $retryException;
                                        }
                                    }
                                } else {
                                    throw $buildException;
                                }
                            }
                        } else {
                            $output[] = 'Skipping npm build: no frontend changes detected.';
                        }
                    }
                } catch (\Throwable $exception) {
                    $this->logNpmDiagnostics($output);
                    throw $exception;
                }
            } else {
                $output[] = 'Skipping npm install: node/npm not available in PATH.';
            }
        }

        if (is_file(base_path('artisan'))) {
            $this->runProcess(['php', 'artisan', 'migrate', '--force'], $output, $repoPath);
            $this->maybeRunAppClearCache($repoPath, $output);
        }

        $enterprisePackage = $this->getEnterprisePackageUpdateStatus($repoPath, $output);
        $output[] = $enterprisePackage['message'] ?? 'Enterprise package update check completed.';
        if (($enterprisePackage['status'] ?? '') === 'update-available') {
            $output[] = 'Enterprise package update available: '.($enterprisePackage['current'] ?? 'unknown').' → '.($enterprisePackage['latest'] ?? 'unknown').'.';
        }

        $this->applyPostUpdatePermissions($repoPath, $output);
    }

    private function maybeRunAppClearCache(string $repoPath, array &$output): void
    {
        $artisan = $repoPath.DIRECTORY_SEPARATOR.'artisan';
        if (! is_file($artisan)) {
            return;
        }

        try {
            if (! $this->artisanCommandExists($repoPath, 'app:clear-cache', $output)) {
                $output[] = 'Skipping app:clear-cache (command not found).';
                return;
            }

            $output[] = 'Running app:clear-cache.';
            $this->runProcess(['php', 'artisan', 'app:clear-cache'], $output, $repoPath, false);
        } catch (\Throwable $exception) {
            $output[] = 'Warning: app:clear-cache failed: '.$exception->getMessage();
        }
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

    private function stashIfDirty(string $repoPath, array &$output, array $htaccessSnapshots = []): bool
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return false;
        }

        if (! $this->tryRevParse($repoPath)) {
            $output[] = 'Local changes detected, but no initial commit exists. Skipping git stash.';
            return false;
        }

        $output[] = 'Local changes detected: stashing tracked changes before update.';
        $command = [
            'git',
            '-C',
            $repoPath,
            'stash',
            'push',
            '-m',
            'gwm-self-update',
            '--',
            '.',
            ':(exclude).htaccess',
        ];

        foreach ($this->selfUpdateExcludedPaths() as $path) {
            $path = trim($path);
            if ($path === '' || $path === '.' || $path === '..') {
                continue;
            }

            $command[] = ':(exclude)'.$path;
        }

        $this->runProcess($command, $output);

        return true;
    }

    private function getWorkingTreeStatus(string $repoPath, array &$output): string
    {
        $process = $this->runProcess(['git', '-C', $repoPath, 'status', '--porcelain'], $output, null, false);
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Unable to check git status for this application.');
        }

        $lines = array_filter(array_map('trim', explode("\n", $process->getOutput())));
        $filtered = [];
        foreach ($lines as $line) {
            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }

            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim(end($parts));
            }

            if ($this->isExcludedPath($path)) {
                continue;
            }

            $filtered[] = $line;
        }

        return trim(implode("\n", $filtered));
    }

    /**
     * @return array<int, string>
     */
    private function getUntrackedFiles(string $repoPath, array &$output): array
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return [];
        }

        $files = [];
        foreach (explode("\n", $status) as $line) {
            if (! str_starts_with($line, '?? ')) {
                continue;
            }

            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }

            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim(end($parts));
            }

            if ($this->isExcludedPath($path)) {
                continue;
            }

            $relative = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
            if ($this->shouldExcludeBackupPath($relative, false, '')) {
                continue;
            }

            $files[] = $relative;
        }

        return array_values(array_unique($files));
    }

    /**
     * @param array<int, string> $paths
     */
    private function backupUntrackedFiles(string $repoPath, array $paths, array &$output): ?string
    {
        if ($paths === []) {
            return null;
        }

        $base = storage_path('app/self-update-untracked');
        if (! is_dir($base)) {
            mkdir($base, 0775, true);
        }

        $timestamp = now()->format('Ymd_His');
        $target = $base.DIRECTORY_SEPARATOR.$timestamp;
        if (! is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $output[] = 'Backing up untracked files before update.';
        foreach ($paths as $relative) {
            $source = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (! file_exists($source)) {
                continue;
            }
            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            $this->copyPath($source, $destination);
        }

        return $target;
    }

    /**
     * @param array<int, string> $paths
     */
    private function removeUntrackedFiles(string $repoPath, array $paths, array &$output): void
    {
        if ($paths === []) {
            return;
        }

        foreach ($paths as $relative) {
            $path = $repoPath.DIRECTORY_SEPARATOR.$relative;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $output[] = 'Removed untracked files before update.';
    }

    private function restoreUntrackedFiles(string $repoPath, string $backupPath, array &$output): void
    {
        if (! is_dir($backupPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupPath, \FilesystemIterator::SKIP_DOTS),
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

        $output[] = 'Restored untracked files after update.';
    }

    private function restoreUntrackedBackups(string $repoPath, array $backups, array &$output): void
    {
        foreach ($backups as $backup) {
            if (! is_string($backup) || $backup === '') {
                continue;
            }

            $this->restoreUntrackedFiles($repoPath, $backup, $output);
        }
    }

    private function mergeFastForward(string $repoPath, string $branch, array &$output, array &$untrackedBackups): void
    {
        try {
            $this->runProcess(['git', '-C', $repoPath, 'merge', '--ff-only', 'origin/'.$branch], $output);
            return;
        } catch (ProcessFailedException $exception) {
            $paths = $this->extractMergeUntrackedPaths($exception);
            if ($paths === []) {
                if ($this->isDivergedFastForwardError($exception)) {
                    $output[] = 'Fast-forward not possible; performing merge with origin/'.$branch.'.';
                    $merge = $this->runProcess(['git', '-C', $repoPath, 'merge', '--no-ff', 'origin/'.$branch], $output, null, false);
                    if (! $merge->isSuccessful()) {
                        $output[] = 'Merge failed; attempting to abort.';
                        $this->runProcess(['git', '-C', $repoPath, 'merge', '--abort'], $output, null, false);
                        throw new ProcessFailedException($merge);
                    }
                    return;
                }

                throw $exception;
            }

            $output[] = 'Merge blocked by untracked files. Backing them up before retry.';
            $backup = $this->backupUntrackedFiles($repoPath, $paths, $output);
            if ($backup) {
                $untrackedBackups[] = $backup;
            }
            $this->removeUntrackedFiles($repoPath, $paths, $output);

            $this->runProcess(['git', '-C', $repoPath, 'merge', '--ff-only', 'origin/'.$branch], $output);
        }
    }

    private function isDivergedFastForwardError(ProcessFailedException $exception): bool
    {
        $text = trim($exception->getProcess()->getErrorOutput());
        if ($text === '') {
            $text = trim($exception->getProcess()->getOutput());
        }

        if ($text === '') {
            return false;
        }

        $text = strtolower($text);

        return str_contains($text, 'diverging branches')
            || str_contains($text, 'not possible to fast-forward')
            || str_contains($text, 'fast-forward, aborting');
    }

    /**
     * @return array<int, string>
     */
    private function extractMergeUntrackedPaths(ProcessFailedException $exception): array
    {
        $text = trim($exception->getProcess()->getErrorOutput());
        if ($text === '') {
            $text = trim($exception->getProcess()->getOutput());
        }

        if ($text === '' || ! str_contains($text, 'untracked working tree files would be overwritten by merge')) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $collect = false;
        $paths = [];

        foreach ($lines as $line) {
            if (! $collect && str_contains($line, 'would be overwritten by merge')) {
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

            if (str_contains($trimmed, ' -> ')) {
                $parts = explode(' -> ', $trimmed);
                $trimmed = trim(end($parts));
            }

            $path = $this->sanitizeMergePath($trimmed);
            if ($path === null) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private function sanitizeMergePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
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

    private function tryRevParse(string $repoPath): ?string
    {
        $localOutput = [];
        $process = $this->runProcess(['git', '-C', $repoPath, 'rev-parse', '--verify', 'HEAD'], $localOutput, null, false);
        if (! $process->isSuccessful()) {
            return null;
        }

        $hash = trim($process->getOutput());
        return $hash !== '' ? $hash : null;
    }

    private function isPathTracked(string $repoPath, string $path): bool
    {
        $localOutput = [];
        $process = $this->runProcess(
            ['git', '-C', $repoPath, 'ls-files', '--error-unmatch', $path],
            $localOutput,
            null,
            false
        );

        return $process->isSuccessful();
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

    private function canRunNpm(array &$output): bool
    {
        $npm = $this->npmBinary();
        if (! $this->binaryAvailable($npm)) {
            $output[] = 'npm binary not found: '.$npm;
            $this->logNpmDiagnostics($output);
            return false;
        }

        if (! $this->binaryAvailable('node')) {
            $output[] = 'node binary not found in PATH.';
            $this->logNpmDiagnostics($output);
            return false;
        }

        return true;
    }

    private function logNpmDiagnostics(array &$output): void
    {
        $env = $this->baseEnv();
        $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
        $output[] = 'npm diagnostic: PATH='.($env[$pathKey] ?? '');

        $npmPath = $this->resolveBinaryPath('npm');
        $nodePath = $this->resolveBinaryPath('node');
        $output[] = 'npm diagnostic: npm='.($npmPath ?: 'not found');
        $output[] = 'npm diagnostic: node='.($nodePath ?: 'not found');

        $this->runProcess(['npm', '--version'], $output, null, false);
        $this->runProcess(['node', '--version'], $output, null, false);
    }

    private function shouldRunNpmInstall(string $repoPath, ?string $fromHash, ?string $toHash, array &$output): bool
    {
        $nodeModules = $repoPath.DIRECTORY_SEPARATOR.'node_modules';
        if (! is_dir($nodeModules)) {
            $output[] = 'npm install required: node_modules is missing.';
            return true;
        }

        return $this->gitPathsChanged($repoPath, $fromHash, $toHash, [
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ]);
    }

    private function shouldRunNpmBuild(string $repoPath, ?string $fromHash, ?string $toHash, array &$output): bool
    {
        return $this->gitPathsChanged($repoPath, $fromHash, $toHash, [
            'resources',
            'vite.config.js',
            'tailwind.config.js',
            'postcss.config.js',
        ]);
    }

    /**
     * @param array<int, string> $paths
     */
    private function gitPathsChanged(string $repoPath, ?string $fromHash, ?string $toHash, array $paths): bool
    {
        if (! $fromHash || ! $toHash) {
            return true;
        }

        $command = ['git', '-C', $repoPath, 'diff', '--name-only', $fromHash.'..'.$toHash, '--'];
        foreach ($paths as $path) {
            $command[] = $path;
        }

        $output = [];
        $process = $this->runProcess($command, $output, null, false);
        if (! $process->isSuccessful()) {
            return true;
        }

        return trim($process->getOutput()) !== '';
    }

    private function prepareViteBuildDirectory(string $repoPath, array &$output, bool $force = false): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $buildPath = $repoPath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';
        if (! is_dir($buildPath)) {
            if (@mkdir($buildPath, 0775, true)) {
                $output[] = 'Created build directory at '.$buildPath;
            }
            return;
        }

        if (! is_writable($buildPath) || $force) {
            $output[] = 'Ensuring build directory is writable: '.$buildPath;
            $this->chmodRecursive($buildPath, 0775);
            if ($force && is_dir($buildPath)) {
                $this->deleteDirectory($buildPath);
                if (@mkdir($buildPath, 0775, true)) {
                    $output[] = 'Recreated build directory at '.$buildPath;
                }
            }
        }
    }

    private function backupBuildManifest(string $repoPath, array &$output): ?string
    {
        $manifest = $repoPath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifest)) {
            return null;
        }

        $backupDir = storage_path('app/build-manifest-backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        $backupPath = $backupDir.DIRECTORY_SEPARATOR.'manifest-'.now()->format('Ymd_His').'.json';
        if (@copy($manifest, $backupPath)) {
            $output[] = 'Backed up build manifest.';
            return $backupPath;
        }

        return null;
    }

    private function restoreBuildManifest(string $repoPath, ?string $backupPath, array &$output): void
    {
        if (! $backupPath || ! is_file($backupPath)) {
            return;
        }

        $manifestDir = $repoPath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';
        if (! is_dir($manifestDir)) {
            mkdir($manifestDir, 0775, true);
        }

        $manifest = $manifestDir.DIRECTORY_SEPARATOR.'manifest.json';
        if (@copy($backupPath, $manifest)) {
            $output[] = 'Restored build manifest from backup.';
        }
    }

    private function isBuildPermissionError(\Throwable $exception): bool
    {
        $message = $exception->getMessage();
        if ($message === '') {
            return false;
        }

        $message = strtolower($message);
        return str_contains($message, 'permission denied')
            || str_contains($message, 'eacces')
            || str_contains($message, 'public/build')
            || str_contains($message, 'node_modules');
    }

    private function chmodRecursive(string $path, int $mode): void
    {
        if (! is_dir($path)) {
            @chmod($path, $mode);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            @chmod($item->getPathname(), $mode);
        }

        @chmod($path, $mode);
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
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
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

    private function resolveBinaryPath(string $binary): ?string
    {
        $command = PHP_OS_FAMILY === 'Windows' ? ['where', $binary] : ['which', $binary];
        $process = new Process($command, null, $this->baseEnv());
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $lines = array_filter(array_map('trim', explode("\n", $process->getOutput())));
        return $lines[0] ?? null;
    }

    private function binaryAvailable(string $binary): bool
    {
        $binary = trim($binary);
        $binary = trim($binary, "\"' ");
        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        $command = PHP_OS_FAMILY === 'Windows' ? ['where', $binary] : ['which', $binary];
        $process = new Process($command, null, $this->baseEnv());
        $process->run();

        return $process->isSuccessful();
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

    private function backupWorkingTree(string $repoPath, array &$output, bool $includeDependencies): string
    {
        $base = storage_path('app/self-update-backups');
        if (! is_dir($base)) {
            mkdir($base, 0775, true);
        }

        $timestamp = now()->format('Ymd_His');
        $target = $base.DIRECTORY_SEPARATOR.$timestamp;
        if (! is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $output[] = 'Creating backup of working tree (excluding .git, storage/logs) at: '.$target;
        if (! $includeDependencies) {
            $output[] = 'Skipping node_modules and vendor in backup (no package file changes detected).';
        }

        $backupRootRelative = ltrim(str_replace($repoPath, '', $base), DIRECTORY_SEPARATOR);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $relative = $iterator->getSubPathname();
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

            if ($this->shouldExcludeBackupPath($relative, $includeDependencies, $backupRootRelative)) {
                continue;
            }

            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }
                continue;
            }

            $destinationDir = dirname($destination);
            if (! is_dir($destinationDir)) {
                mkdir($destinationDir, 0775, true);
            }

            copy($item->getPathname(), $destination);
        }

        return $target;
    }

    private function shouldExcludeBackupPath(string $relative, bool $includeDependencies, string $backupRootRelative): bool
    {
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        if ($relative === '.git' || str_starts_with($relative, '.git'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        if ($backupRootRelative !== '' && ($relative === $backupRootRelative || str_starts_with($relative, $backupRootRelative.DIRECTORY_SEPARATOR))) {
            return true;
        }

        if (! $includeDependencies) {
            if ($relative === 'node_modules' || str_starts_with($relative, 'node_modules'.DIRECTORY_SEPARATOR)) {
                return true;
            }
            if ($relative === 'vendor' || str_starts_with($relative, 'vendor'.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        if ($relative === 'storage'.DIRECTORY_SEPARATOR.'logs' || str_starts_with($relative, 'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        foreach ($this->selfUpdateExcludedPaths() as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
            if ($relative === $path || str_starts_with($relative, $path.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function selfUpdateExcludedPaths(): array
    {
        $paths = config('gitmanager.self_update.exclude_paths', ['docs']);
        if (! is_array($paths)) {
            $paths = [$paths];
        }

        $normalized = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $normalized[] = $path;
            }
        }

        return $normalized;
    }

    private function isExcludedPath(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        foreach ($this->selfUpdateExcludedPaths() as $exclude) {
            $exclude = ltrim(str_replace('\\', '/', $exclude), '/');
            if ($exclude === '') {
                continue;
            }

            if ($path === $exclude || str_starts_with($path, $exclude.'/')) {
                return true;
            }
        }

        return false;
    }

    private function removeExcludedPaths(string $repoPath, array &$output): void
    {
        foreach ($this->selfUpdateExcludedPaths() as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $target = $repoPath.DIRECTORY_SEPARATOR.$path;
            if (! file_exists($target)) {
                continue;
            }

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                $output[] = 'Excluded path removed: '.$path;
                continue;
            }

            if (@unlink($target)) {
                $output[] = 'Excluded path removed: '.$path;
            }
        }
    }

    private function shouldIncludeDependencyDirs(string $repoPath, array &$output): bool
    {
        $status = $this->getWorkingTreeStatus($repoPath, $output);
        if ($status === '') {
            return false;
        }

        $targets = [
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ];

        foreach (explode("\n", $status) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }

            $basename = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path));
            if (in_array($basename, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $process = new Process($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $process->setTimeout(900);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
        });

        if ($throwOnFailure && ! $process->isSuccessful()) {
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
}
