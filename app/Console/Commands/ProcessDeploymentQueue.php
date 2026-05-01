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

        $this->applyRuntimeBudget($limit);
        $this->appendWorkerLog('Queue processor started with limit '.($limit > 0 ? (string) $limit : 'unlimited').'.');

        $processed = $queue->processNext($limit);
        $this->info("Processed {$processed} queued item(s).");
        $this->appendWorkerLog("Queue processor finished. Processed {$processed} item(s).");

        return self::SUCCESS;
    }

    private function applyRuntimeBudget(int $limit): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $processTimeout = (int) config('gitmanager.deployments.process_timeout', config('gitmanager.process_timeout', 900));
        if ($processTimeout <= 0 || $limit <= 0) {
            @set_time_limit(0);

            return;
        }

        @set_time_limit(($processTimeout + 300) * max(1, $limit));
    }

    private function appendWorkerLog(string $message): void
    {
        try {
            $path = storage_path('logs/deployment-queue-worker.log');
            $directory = dirname($path);
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            file_put_contents($path, '['.now()->toDateTimeString().'] '.$message.PHP_EOL, FILE_APPEND);
        } catch (\Throwable) {
            // Best-effort diagnostics only.
        }
    }
}
