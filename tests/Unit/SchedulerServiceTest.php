<?php

namespace Tests\Unit;

use App\Services\SchedulerService;
use Tests\TestCase;

class SchedulerServiceTest extends TestCase
{
    public function test_cron_command_quotes_paths_and_keeps_output_for_diagnostics(): void
    {
        config()->set('gitmanager.php_binary', '/opt/php 8.2/bin/php');

        $command = app(SchedulerService::class)->cronCommand();

        $this->assertStringStartsWith('* * * * * cd '.escapeshellarg(base_path()).' && ', $command);
        $this->assertStringContainsString(escapeshellarg('/opt/php 8.2/bin/php').' artisan scheduler:run', $command);
        $this->assertStringContainsString(' >> '.escapeshellarg(storage_path('logs/scheduler-cron.log')).' 2>&1', $command);
        $this->assertStringNotContainsString('/dev/null', $command);
    }
}
