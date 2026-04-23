<?php

namespace App\Console\Commands;

use App\Services\LogCleanupService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class LogCleanup extends Command
{
    protected $signature = 'logs:cleanup
        {--days= : Clear stored deployment/self-update logs older than this many days}
        {--all : Clear all stored deployment/self-update logs}
        {--dry-run : Show what would be cleared without writing changes}
        {--vacuum : Run SQLite VACUUM after clearing logs}';

    protected $description = 'Clear stored deployment and self-update console logs from the database while keeping history rows.';

    public function handle(LogCleanupService $cleanup, SettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $vacuum = (bool) $this->option('vacuum');
        $all = (bool) $this->option('all');
        $daysOption = $this->option('days');

        if ($all) {
            $result = $cleanup->clearAll($dryRun, $vacuum);
            $scope = 'all stored logs';
        } else {
            if ($daysOption === null) {
                $enabled = (bool) $settings->get('system.logs.cleanup_enabled', false);
                if (! $enabled) {
                    $this->warn('Automatic log cleanup is disabled. Use --days=<n> or --all to run cleanup manually.');

                    return self::FAILURE;
                }

                $days = LogCleanupService::normalizeRetentionDays(
                    $settings->get('system.logs.retention_days', LogCleanupService::DEFAULT_RETENTION_DAYS)
                );
            } else {
                $days = (int) $daysOption;
                if ($days <= 0) {
                    $this->warn('Use --all to clear every stored log, or provide --days=1 or higher.');

                    return self::FAILURE;
                }

                $days = LogCleanupService::normalizeRetentionDays($days);
            }

            $result = $cleanup->cleanupOlderThanDays($days, $dryRun);
            $scope = "logs older than {$days} day(s)";
        }

        $appUpdates = $result['app_updates'];
        $deployments = $result['deployments'];

        $this->line(($dryRun ? 'Would clear' : 'Cleared').' '.$scope.'.');
        $this->line(sprintf(
            'App updates: %d record(s), %s',
            $appUpdates['records'],
            $this->formatBytes((int) $appUpdates['bytes'])
        ));
        $this->line(sprintf(
            'Deployments: %d record(s), %s',
            $deployments['records'],
            $this->formatBytes((int) $deployments['bytes'])
        ));
        $this->line(sprintf(
            'Total: %d record(s), %s',
            $result['total_records'],
            $this->formatBytes((int) $result['total_bytes'])
        ));

        if ($vacuum && ! $dryRun) {
            if ($result['vacuumed']) {
                $this->info('SQLite VACUUM completed.');
            } else {
                $this->warn('VACUUM skipped because the current database driver is not SQLite.');
            }
        }

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return number_format($value, 2).' '.$unit;
            }

            $value /= 1024;
        }

        return $bytes.' B';
    }
}
