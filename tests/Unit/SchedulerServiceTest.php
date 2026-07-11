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

    public function test_manual_run_starts_a_worker_when_one_is_not_running(): void
    {
        $runs = 0;
        $launched = 0;

        $scheduler = new class($runs, $launched) extends SchedulerService
        {
            public function __construct(private int &$runs, private int &$launched) {}

            protected function isWorkerRunning(): bool
            {
                return false;
            }

            protected function launchWorkerInBackground(): array
            {
                $this->launched++;

                return ['success' => true, 'started' => true, 'message' => 'started'];
            }

            public function runSchedulerOnce(string $source = 'manual'): array
            {
                $this->runs++;

                return ['success' => true, 'message' => 'Scheduler executed successfully.', 'output' => ''];
            }
        };

        $result = $scheduler->runScheduleNow();

        $this->assertSame(1, $launched);
        $this->assertSame(1, $runs);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('worker started', strtolower($result['message']));
    }

    public function test_manual_run_does_not_start_a_second_worker_when_one_is_healthy(): void
    {
        $runs = 0;

        $scheduler = new class($runs) extends SchedulerService
        {
            public function __construct(private int &$runs) {}

            protected function isWorkerRunning(): bool
            {
                return true;
            }

            protected function launchWorkerInBackground(): array
            {
                $this->fail('A healthy worker must not be launched twice.');
            }

            public function runSchedulerOnce(string $source = 'manual'): array
            {
                $this->runs++;

                return ['success' => true, 'message' => 'Scheduler executed successfully.', 'output' => ''];
            }
        };

        $result = $scheduler->runScheduleNow();

        $this->assertSame(1, $runs);
        $this->assertTrue($result['success']);
        $this->assertSame('Scheduler executed successfully.', $result['message']);
    }

    public function test_scheduler_worker_honors_a_post_update_restart_request(): void
    {
        $scheduler = new class extends SchedulerService
        {
            public function runSchedulerOnce(string $source = 'manual'): array
            {
                return ['success' => true, 'message' => 'Scheduler executed successfully.', 'output' => ''];
            }
        };
        $scheduler->requestWorkerRestart();
        $this->app->instance(SchedulerService::class, $scheduler);

        $exitCode = Artisan::call('scheduler:work', ['--once' => true, '--sleep' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('restart requested after application update', Artisan::output());
        $this->assertFalse($scheduler->consumeWorkerRestartRequest());
    }

    public function test_recovery_command_starts_the_worker_when_needed(): void
    {
        $scheduler = new class extends SchedulerService
        {
            public function ensureWorkerRunning(): array
            {
                return ['success' => true, 'started' => true, 'message' => 'Scheduler worker started in the background.'];
            }
        };
        $this->app->instance(SchedulerService::class, $scheduler);

        $exitCode = Artisan::call('scheduler:ensure-worker', ['--delay' => 0]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scheduler worker started', Artisan::output());
    }
}
