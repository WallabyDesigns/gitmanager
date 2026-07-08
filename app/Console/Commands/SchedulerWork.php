<?php

namespace App\Console\Commands;

use App\Services\SchedulerService;
use Illuminate\Console\Command;

class SchedulerWork extends Command
{
    protected $signature = 'scheduler:work
        {--sleep= : Seconds to wait between scheduler checks.}
        {--max-runs= : Stop after this many scheduler checks.}
        {--once : Run one scheduler check and exit.}';

    protected $description = 'Run the GitManager scheduler continuously without relying on cron timing.';

    private bool $shouldQuit = false;

    public function handle(SchedulerService $scheduler): int
    {
        $this->disableRuntimeLimit();
        $this->listenForSignals();

        $sleep = $this->sleepSeconds();
        $maxRuns = $this->maxRuns();
        $runs = 0;

        $this->info("Scheduler worker started. Checking due tasks every {$sleep} second(s).");

        do {
            $runs++;
            $result = $scheduler->runSchedulerOnce('worker');

            $message = '['.now()->toDateTimeString().'] '.$result['message'];
            $result['success'] ? $this->info($message) : $this->error($message);

            if ($this->option('once') || ($maxRuns !== null && $runs >= $maxRuns) || $this->shouldQuit) {
                break;
            }

            $this->sleepInterruptibly($sleep);
        } while (! $this->shouldQuit);

        $this->info('Scheduler worker stopped.');

        return self::SUCCESS;
    }

    private function disableRuntimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    private function listenForSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldQuit = true;
            });
        }
    }

    private function sleepSeconds(): int
    {
        $value = $this->option('sleep');
        if ($value === null || $value === '') {
            $value = config('gitmanager.scheduler.worker_sleep_seconds', 60);
        }

        return max(1, min(3600, (int) $value));
    }

    private function maxRuns(): ?int
    {
        $value = $this->option('max-runs');
        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function sleepInterruptibly(int $seconds): void
    {
        for ($elapsed = 0; $elapsed < $seconds && ! $this->shouldQuit; $elapsed++) {
            sleep(1);
        }
    }
}
