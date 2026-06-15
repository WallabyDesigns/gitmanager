<?php

namespace App\Console\Commands;

use App\Services\SchedulerService;
use Illuminate\Console\Command;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Record a scheduler heartbeat for health checks.';

    public function handle(SchedulerService $scheduler): int
    {
        // Release locks stuck longer than 10 minutes before recording the heartbeat
        // so the next schedule:run dispatch finds them clear.
        $scheduler->releaseStuckScheduleLocks(10);
        $scheduler->recordHeartbeat('schedule');
        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
