<?php

namespace Tests\Unit;

use App\Services\SchedulerService;
use Illuminate\Support\Facades\Artisan;
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

    public function test_scheduler_work_can_run_once_without_sleeping(): void
    {
        $runs = 0;

        $this->app->instance(SchedulerService::class, new class($runs) extends SchedulerService
        {
            public function __construct(private int &$runs) {}

            public function runSchedulerOnce(string $source = 'manual'): array
            {
                $this->runs++;

                return [
                    'success' => true,
                    'message' => "Scheduler executed from {$source}.",
                    'output' => '',
                ];
            }
        });

        $exitCode = Artisan::call('scheduler:work', ['--once' => true, '--sleep' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $runs);
        $this->assertStringContainsString('Scheduler worker stopped.', Artisan::output());
    }
}
