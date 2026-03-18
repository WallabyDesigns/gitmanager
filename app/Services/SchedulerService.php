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
        $this->writeHeartbeatFile($source);
    }

    public function recordManualRun(): void
    {
        Cache::forever(self::MANUAL_KEY, now()->toDateTimeString());
    }

    public function lastHeartbeat(): ?Carbon
    {
        $value = Cache::get(self::HEARTBEAT_KEY);
        if ($value) {
            return Carbon::parse($value);
        }

        $file = $this->readHeartbeatFile();
        return $file['timestamp'] ?? null;
    }

    public function lastManualRun(): ?Carbon
    {
        $value = Cache::get(self::MANUAL_KEY);
        return $value ? Carbon::parse($value) : null;
    }

    public function lastSource(): ?string
    {
        $value = Cache::get(self::SOURCE_KEY);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $file = $this->readHeartbeatFile();
        return $file['source'] ?? null;
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

        $base = base_path();
        $log = storage_path('logs/scheduler.log');
        $baseArg = escapeshellarg($base);
        $logArg = escapeshellarg($log);

        return '* * * * * cd '.$baseArg.' && '.$php.' artisan schedule:run >> '.$logArg.' 2>&1';
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

        $lines = preg_split('/\r\n|\r|\n/', $current) ?: [];
        $updated = false;
        $artisanPath = base_path('artisan');
        $normalizedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $normalizedLines[] = $line;
                continue;
            }

            if (Str::contains($line, $artisanPath) && Str::contains($line, 'schedule:run')) {
                $normalizedLines[] = $command;
                $updated = true;
                continue;
            }

            $normalizedLines[] = $line;
        }

        if (! $updated) {
            $normalizedLines[] = $command;
        }

        $content = rtrim(implode("\n", $normalizedLines))."\n";
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

    private function writeHeartbeatFile(string $source): void
    {
        $path = $this->heartbeatPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = [
            'timestamp' => now()->toDateTimeString(),
            'source' => $source,
        ];

        if (@file_put_contents($path, json_encode($payload)) !== false) {
            @chmod($path, 0664);
        }
    }

    /**
     * @return array{timestamp?: Carbon, source?: string}
     */
    private function readHeartbeatFile(): array
    {
        $path = $this->heartbeatPath();
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (! $contents) {
            return [];
        }

        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return [];
        }

        $timestamp = $data['timestamp'] ?? null;
        $source = is_string($data['source'] ?? null) ? $data['source'] : null;

        return [
            'timestamp' => $timestamp ? Carbon::parse($timestamp) : null,
            'source' => $source,
        ];
    }

    private function heartbeatPath(): string
    {
        return storage_path('app/scheduler-heartbeat.json');
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
