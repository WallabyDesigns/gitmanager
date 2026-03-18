<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SchedulerService
{
    private const HEARTBEAT_KEY = 'gwm_scheduler_last_heartbeat';
    private const MANUAL_KEY = 'gwm_scheduler_last_manual';
    private const SOURCE_KEY = 'gwm_scheduler_last_source';

    public function recordHeartbeat(string $source = 'schedule'): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toDateTimeString());
        Cache::forever(self::SOURCE_KEY, $source);
    }

    public function recordManualRun(): void
    {
        Cache::forever(self::MANUAL_KEY, now()->toDateTimeString());
    }

    public function lastHeartbeat(): ?Carbon
    {
        $value = Cache::get(self::HEARTBEAT_KEY);
        return $value ? Carbon::parse($value) : null;
    }

    public function lastManualRun(): ?Carbon
    {
        $value = Cache::get(self::MANUAL_KEY);
        return $value ? Carbon::parse($value) : null;
    }

    public function lastSource(): ?string
    {
        $value = Cache::get(self::SOURCE_KEY);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isHealthy(?int $graceSeconds = null): bool
    {
        $grace = $graceSeconds ?? (int) config('gitmanager.scheduler.stale_seconds', 120);
        $last = $this->lastHeartbeat();

        return $last !== null && $last->greaterThan(now()->subSeconds($grace));
    }

    public function cronCommand(): string
    {
        $php = trim((string) config('gitmanager.php_binary', 'php'));
        $php = trim($php, "\"' ");
        if ($php === '') {
            $php = 'php';
        }

        $artisan = base_path('artisan');

        return '* * * * * '.$php.' '.$artisan.' schedule:run >> /dev/null 2>&1';
    }

    public function installCron(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'success' => false,
                'message' => 'Automatic cron install is not supported on Windows. Use Task Scheduler instead.',
            ];
        }

        $command = $this->cronCommand();
        $current = $this->readCrontab();
        if ($current === null) {
            return [
                'success' => false,
                'message' => 'Unable to read current crontab. Install the cron line manually.',
            ];
        }

        if (Str::contains($current, $command)) {
            return [
                'success' => true,
                'message' => 'Cron entry already exists.',
            ];
        }

        $content = rtrim($current)."\n".$command."\n";
        $tmp = tempnam(sys_get_temp_dir(), 'gwm-cron-');
        if (! $tmp) {
            return [
                'success' => false,
                'message' => 'Unable to create temporary file for cron install.',
            ];
        }

        file_put_contents($tmp, $content);
        $process = new Process(['crontab', $tmp]);
        $process->run();
        @unlink($tmp);

        if (! $process->isSuccessful()) {
            return [
                'success' => false,
                'message' => 'Cron install failed. Install the cron line manually.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Cron entry installed successfully.',
        ];
    }

    private function readCrontab(): ?string
    {
        $process = new Process(['crontab', '-l']);
        $process->run();

        if (! $process->isSuccessful()) {
            return '';
        }

        return $process->getOutput();
    }

    /**
     * @return array{success: bool, message: string, output?: string}
     */
    public function runScheduleNow(): array
    {
        $php = trim((string) config('gitmanager.php_binary', 'php'));
        $php = trim($php, "\"' ");
        if ($php === '') {
            $php = 'php';
        }

        $process = new Process([$php, base_path('artisan'), 'schedule:run']);
        $process->setTimeout(600);
        $process->run(null, $this->baseEnv());

        if (! $process->isSuccessful()) {
            return [
                'success' => false,
                'message' => 'Scheduler run failed.',
                'output' => trim($process->getErrorOutput() ?: $process->getOutput()),
            ];
        }

        return [
            'success' => true,
            'message' => 'Scheduler executed successfully.',
            'output' => trim($process->getOutput()),
        ];
    }

    private function baseEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];

        $extraPath = trim((string) config('gitmanager.process_path', ''));
        $extraPath = trim($extraPath, "\"' ");
        if ($extraPath !== '') {
            $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
            $current = $env[$pathKey] ?? '';
            $env[$pathKey] = $extraPath.PATH_SEPARATOR.$current;
        }

        $phpBinary = trim((string) config('gitmanager.php_binary', 'php'));
        $phpBinary = trim($phpBinary, "\"' ");
        if ($phpBinary !== '' && (str_contains($phpBinary, DIRECTORY_SEPARATOR) || str_contains($phpBinary, '/'))) {
            $phpDir = dirname($phpBinary);
            if ($phpDir !== '' && $phpDir !== '.') {
                $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
                $current = $env[$pathKey] ?? '';
                if (! str_contains($current, $phpDir)) {
                    $env[$pathKey] = $phpDir.PATH_SEPARATOR.$current;
                }
            }
        }

        return $env;
    }
}
