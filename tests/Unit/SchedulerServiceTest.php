<?php

namespace Tests\Unit;

use App\Services\SchedulerService;
use Tests\TestCase;

class SchedulerServiceTest extends TestCase
{
    public function test_cron_command_uses_cron_php_script_and_keeps_output_for_diagnostics(): void
    {
        $command = app(SchedulerService::class)->cronCommand();

        $this->assertStringStartsWith('* * * * * php ', $command);
        $this->assertStringContainsString('php '.escapeshellarg(base_path('cron.php')), $command);
        $this->assertStringContainsString(' >> '.escapeshellarg(storage_path('logs/scheduler-cron.log')).' 2>&1', $command);
        $this->assertStringNotContainsString('artisan', $command);
        $this->assertStringNotContainsString('/dev/null', $command);
    }
}
