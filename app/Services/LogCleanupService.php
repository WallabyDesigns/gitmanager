<?php

namespace App\Services;

use App\Models\AppUpdate;
use App\Models\Deployment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LogCleanupService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public const MAX_RETENTION_DAYS = 3650;

    public static function normalizeRetentionDays(mixed $days, int $default = self::DEFAULT_RETENTION_DAYS): int
    {
        $days = (int) $days;
        if ($days < 1) {
            $days = $default;
        }

        return min($days, self::MAX_RETENTION_DAYS);
    }

    public function cleanupOlderThanDays(int $days, bool $dryRun = false): array
    {
        $days = self::normalizeRetentionDays($days);
        $cutoff = now()->subDays($days);

        return $this->cleanup($cutoff, $dryRun, false);
    }

    public function clearAll(bool $dryRun = false, bool $vacuum = false): array
    {
        return $this->cleanup(null, $dryRun, $vacuum);
    }

    public function vacuumIfSupported(): bool
    {
        if (DB::getDriverName() !== 'sqlite') {
            return false;
        }

        DB::statement('VACUUM');

        return true;
    }

    private function cleanup(?CarbonInterface $cutoff, bool $dryRun, bool $vacuum): array
    {
        $appUpdateQuery = $this->queryForLogCleanup(AppUpdate::query(), $cutoff);
        $deploymentQuery = $this->queryForLogCleanup(Deployment::query(), $cutoff);

        $appUpdates = $this->summarizeQuery($appUpdateQuery, 'app updates');
        $deployments = $this->summarizeQuery($deploymentQuery, 'deployments');

        $vacuumed = false;

        if (! $dryRun) {
            if ($appUpdates['records'] > 0) {
                (clone $appUpdateQuery)->update(['output_log' => null]);
            }

            if ($deployments['records'] > 0) {
                (clone $deploymentQuery)->update(['output_log' => null]);
            }

            if ($vacuum && ($appUpdates['records'] > 0 || $deployments['records'] > 0)) {
                $vacuumed = $this->vacuumIfSupported();
            }
        }

        return [
            'cutoff' => $cutoff?->toDateTimeString(),
            'dry_run' => $dryRun,
            'vacuumed' => $vacuumed,
            'app_updates' => $appUpdates,
            'deployments' => $deployments,
            'total_records' => $appUpdates['records'] + $deployments['records'],
            'total_bytes' => $appUpdates['bytes'] + $deployments['bytes'],
        ];
    }

    private function queryForLogCleanup(Builder $query, ?CarbonInterface $cutoff): Builder
    {
        $model = $query->getModel();
        $table = $model->getTable();

        $query->whereNotNull("{$table}.output_log");

        if ($cutoff === null) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($table, $cutoff): void {
            $builder->where("{$table}.started_at", '<', $cutoff)
                ->orWhere(function (Builder $nested) use ($table, $cutoff): void {
                    $nested->whereNull("{$table}.started_at")
                        ->where("{$table}.created_at", '<', $cutoff);
                });
        });
    }

    private function summarizeQuery(Builder $query, string $label): array
    {
        $model = $query->getModel();
        $table = $model->getTable();

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(LENGTH(output_log)), 0) as aggregate_bytes')
            ->first();

        return [
            'label' => $label,
            'records' => (int) ($summary->aggregate_count ?? 0),
            'bytes' => (int) ($summary->aggregate_bytes ?? 0),
            'table' => $table,
        ];
    }
}
