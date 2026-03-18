<?php

namespace App\Console\Commands;

use App\Services\SchedulerService;
use Illuminate\Console\Command;

class SchedulerRun extends Command
{
    protected $signature = 'scheduler:run';

    protected $description = 'Run the Laravel scheduler and record heartbeat/logs.';

    public function handle(SchedulerService $scheduler): int
    {
        $result = $scheduler->runSchedulerOnce('schedule');

        if (! $result['success']) {
            $this->error($result['message']);
            return self::FAILURE;
        }

        $this->info($result['message']);
        return self::SUCCESS;
    }
}
