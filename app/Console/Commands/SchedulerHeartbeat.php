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
        $scheduler->recordHeartbeat('schedule');
        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
