<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
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
        $baseArg = escapeshellarg($base);
        return '* * * * * cd '.$baseArg.' && '.$php.' artisan scheduler:run >/dev/null 2>&1';
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

        $run = $this->runSchedulerOnce('cron-install');
        if (! $run['success']) {
            return [
                'success' => true,
                'message' => 'Cron entry installed, but the scheduler run failed. Check the Scheduler Error Log.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Cron entry installed successfully and scheduler executed.',
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
        $paths = [$this->heartbeatPath(), $this->legacyHeartbeatPath()];
        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if (! $contents) {
                continue;
            }

            $data = json_decode($contents, true);
            if (! is_array($data)) {
                continue;
            }

            $timestamp = $data['timestamp'] ?? null;
            $source = is_string($data['source'] ?? null) ? $data['source'] : null;

            return [
                'timestamp' => $timestamp ? Carbon::parse($timestamp) : null,
                'source' => $source,
            ];
        }

        return [];
    }

    private function heartbeatPath(): string
    {
        return storage_path('logs/scheduler-heartbeat.json');
    }

    private function legacyHeartbeatPath(): string
    {
        return storage_path('app/scheduler-heartbeat.json');
    }

    /**
     * @return array{success: bool, message: string, output?: string}
     */
    public function runScheduleNow(): array
    {
        return $this->runSchedulerOnce('manual');
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

    /**
     * @return array{success: bool, message: string, output?: string}
     */
    public function runSchedulerOnce(string $source = 'manual'): array
    {
        $exitCode = Artisan::call('schedule:run');
        $output = trim(Artisan::output());

        $hadIssue = $exitCode !== 0 || $this->outputHasFailure($output);
        $this->recordHeartbeat($source);

        if ($hadIssue) {
            $summary = $this->summarizeIssue($output) ?: 'Scheduler reported a failure.';
            $this->logSchedulerIssue($summary, $output);

            return [
                'success' => false,
                'message' => 'Scheduler ran with issues.',
                'output' => $output,
            ];
        }

        return [
            'success' => true,
            'message' => 'Scheduler executed successfully.',
            'output' => $output,
        ];
    }

    /**
     * @return array<int, array{message: string, count: int, first_seen: string, last_seen: string, output?: string}>
     */
    public function schedulerLogEntries(): array
    {
        $path = $this->schedulerLogPath();
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path) ?: '', true);
        if (! is_array($data) || ! isset($data['entries']) || ! is_array($data['entries'])) {
            return [];
        }

        return $data['entries'];
    }

    private function logSchedulerIssue(string $message, string $output = ''): void
    {
        $path = $this->schedulerLogPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $data = [];
        if (is_file($path)) {
            $decoded = json_decode(file_get_contents($path) ?: '', true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $entries = $data['entries'] ?? [];
        $entries = is_array($entries) ? $entries : [];
        $now = now()->toDateTimeString();

        if (! empty($entries) && ($entries[0]['message'] ?? '') === $message) {
            $entries[0]['count'] = (int) ($entries[0]['count'] ?? 1) + 1;
            $entries[0]['last_seen'] = $now;
            if ($output !== '') {
                $entries[0]['output'] = $this->trimOutput($output);
            }
        } else {
            array_unshift($entries, [
                'message' => $message,
                'count' => 1,
                'first_seen' => $now,
                'last_seen' => $now,
                'output' => $output !== '' ? $this->trimOutput($output) : null,
            ]);
        }

        $entries = array_slice($entries, 0, 25);

        $payload = [
            'updated_at' => $now,
            'entries' => $entries,
        ];

        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
        @chmod($path, 0664);
    }

    private function outputHasFailure(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        return (bool) preg_match('/\bFAIL\b|\bERROR\b|\bEXCEPTION\b/i', $output);
    }

    private function summarizeIssue(string $output): string
    {
        if ($output === '') {
            return '';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $output) ?: [])));
        foreach ($lines as $line) {
            if (preg_match('/\bFAIL\b|\bERROR\b|\bEXCEPTION\b/i', $line)) {
                return $line;
            }
        }

        return $lines[0] ?? '';
    }

    private function trimOutput(string $output): string
    {
        $output = trim($output);
        if (mb_strlen($output) <= 2000) {
            return $output;
        }

        return mb_substr($output, 0, 2000).'…';
    }

    private function schedulerLogPath(): string
    {
        return storage_path('app/scheduler-errors.json');
    }
}
