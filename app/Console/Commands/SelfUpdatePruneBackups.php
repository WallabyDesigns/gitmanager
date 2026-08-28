<?php

namespace App\Console\Commands;

use App\Services\SelfUpdateService;
use Illuminate\Console\Command;

class SelfUpdatePruneBackups extends Command
{
    protected $signature = 'self-update:prune-backups
        {--dry-run : Show what would be removed without deleting anything}';

    protected $description = 'Prune old self-update backup directories, keeping only the most recent few for manual rollback.';

    public function handle(SelfUpdateService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $service->pruneBackups($dryRun);

        foreach ($result as $summary) {
            $this->line(sprintf(
                '%s: %d total, %s %d, keeping %d',
                $summary['path'],
                $summary['total'],
                $dryRun ? 'would remove' : 'removed',
                $summary['removed'],
                $summary['kept']
            ));
        }

        return self::SUCCESS;
    }
}
