<?php

namespace App\Console\Commands;

use App\Services\DeploymentQueueService;
use Illuminate\Console\Command;

class ProcessDeploymentQueue extends Command
{
    protected $signature = 'deployments:process-queue {--limit=}';

    protected $description = 'Process queued task requests.';

    public function handle(DeploymentQueueService $queue): int
    {
        if (! config('gitmanager.deploy_queue.enabled', true)) {
            $this->info('Task queue is disabled.');
            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = $limitOption !== null && $limitOption !== ''
            ? (int) $limitOption
            : (int) config('gitmanager.deploy_queue.batch_size', 0);
        $processed = $queue->processNext($limit);
        $this->info("Processed {$processed} queued item(s).");

        return self::SUCCESS;
    }
}
