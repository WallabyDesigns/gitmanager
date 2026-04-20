<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class EnvBackupService
{
    private const MAX_BACKUPS = 20;

    public function storageDir(): string
    {
        return storage_path('app/env-backups');
    }

    /**
     * Create a timestamped backup of the current .env.
     * Returns the backup filename.
     */
    public function backup(string $label = ''): string
    {
        $dir = $this->storageDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $src = base_path('.env');

        if (! is_file($src)) {
            throw new \RuntimeException('.env file not found, cannot create backup.');
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $safeLbl = $label !== '' ? '_'.preg_replace('/[^a-z0-9\-_]/i', '', $label) : '';
        $filename = "env-backup-{$timestamp}{$safeLbl}.txt";

        File::copy($src, $dir.DIRECTORY_SEPARATOR.$filename);
        $this->pruneOldBackups();

        return $filename;
    }

    /**
     * List all backups, newest first.
     *
     * @return array<int, array{filename: string, created_at: string, size: int, label: string}>
     */
    public function list(): array
    {
        $dir = $this->storageDir();

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'env-backup-*.txt');

        if (! is_array($files)) {
            return [];
        }

        $backups = [];

        foreach ($files as $path) {
            $filename = basename($path);
            $stat = @stat($path);
            $mtime = is_array($stat) ? (int) $stat['mtime'] : 0;

            preg_match('/env-backup-[\d_-]+(?:_(.+))?\.txt$/', $filename, $m);

            $backups[] = [
                'filename' => $filename,
                'created_at' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '—',
                'size' => is_array($stat) ? (int) $stat['size'] : 0,
                'label' => $m[1] ?? '',
            ];
        }

        usort($backups, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    /**
     * Restore a backup to .env (automatically backs up current .env first).
     */
    public function restore(string $filename): void
    {
        $this->validateFilename($filename);

        $src = $this->storageDir().DIRECTORY_SEPARATOR.$filename;

        if (! is_file($src)) {
            throw new \RuntimeException("Backup file not found: {$filename}");
        }

        // Preserve the current .env before overwriting
        if (is_file(base_path('.env'))) {
            $this->backup('pre-restore');
        }

        File::copy($src, base_path('.env'));

        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
        }
    }

    /**
     * Delete a backup file.
     */
    public function delete(string $filename): void
    {
        $this->validateFilename($filename);

        $path = $this->storageDir().DIRECTORY_SEPARATOR.$filename;

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function validateFilename(string $filename): void
    {
        if (
            basename($filename) !== $filename
            || ! preg_match('/^env-backup-[\w\-]+\.txt$/', $filename)
        ) {
            throw new \InvalidArgumentException('Invalid backup filename.');
        }
    }

    private function pruneOldBackups(): void
    {
        $dir = $this->storageDir();
        $files = glob($dir.DIRECTORY_SEPARATOR.'env-backup-*.txt');

        if (! is_array($files) || count($files) <= self::MAX_BACKUPS) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));

        $toDelete = array_slice($files, 0, count($files) - self::MAX_BACKUPS);

        foreach ($toDelete as $file) {
            unlink($file);
        }
    }
}
