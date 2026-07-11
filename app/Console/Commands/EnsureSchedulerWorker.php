<?php

namespace App\Console\Commands;

use App\Services\SchedulerService;
use Illuminate\Console\Command;

class EnsureSchedulerWorker extends Command
{
    protected $signature = 'scheduler:ensure-worker {--delay=0 : Seconds to wait before checking the worker.}';

    protected $description = 'Start the scheduler worker when it is not running.';

    public function handle(SchedulerService $scheduler): int
    {
        $delay = max(0, min(3600, (int) $this->option('delay')));
        if ($delay > 0) {
            sleep($delay);
        }

        $result = $scheduler->ensureWorkerRunning();
        $message = (string) ($result['message'] ?? 'Scheduler worker recovery check completed.');
        ($result['success'] ?? false) ? $this->info($message) : $this->error($message);

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
