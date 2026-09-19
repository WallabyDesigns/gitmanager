<?php

namespace App\Logging;

use Illuminate\Support\Facades\Mail;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Throwable;

/**
 * Custom Monolog channel factory ("custom" driver + "via") that rotates
 * the log file by size instead of by date. Laravel's built-in "daily"
 * channel only rotates on day boundaries, so a noisy day can still grow
 * unbounded — this checks the file size on each request and rotates
 * before writing once the configured threshold is crossed.
 *
 * Optionally emails an alert when a rotation actually happens, since a log
 * filling up fast is usually a symptom of something erroring repeatedly,
 * not just steady-state noise.
 */
class SizeRotatingLogger
{
    public function __invoke(array $config): Logger
    {
        $path = $config['path'] ?? storage_path('logs/laravel.log');
        $maxBytes = (int) ($config['max_bytes'] ?? 10 * 1024 * 1024);
        $keep = (int) ($config['keep'] ?? 3);
        $alertEmail = $config['alert_email'] ?? null;
        $cooldown = (int) ($config['alert_cooldown'] ?? 3600);

        $this->rotateIfNeeded($path, $maxBytes, $keep, $alertEmail, $cooldown);

        $handler = new StreamHandler($path, Logger::toMonologLevel($config['level'] ?? 'debug'));
        $handler->setFormatter(new LineFormatter(null, null, true, true));

        return new Logger('sized', [$handler]);
    }

    private function rotateIfNeeded(string $path, int $maxBytes, int $keep, ?string $alertEmail, int $cooldown): void
    {
        clearstatcache(true, $path);

        if ($maxBytes <= 0 || ! is_file($path) || filesize($path) < $maxBytes) {
            return;
        }

        $sizeAtRotation = filesize($path);

        if ($keep <= 0) {
            @unlink($path);
        } else {
            @unlink("{$path}.{$keep}");

            for ($i = $keep - 1; $i >= 1; $i--) {
                $from = "{$path}.{$i}";

                if (is_file($from)) {
                    @rename($from, "{$path}.".($i + 1));
                }
            }

            @rename($path, "{$path}.1");
        }

        if ($alertEmail) {
            $this->notify($path, $sizeAtRotation, $maxBytes, $alertEmail, $cooldown);
        }
    }

    private function notify(string $path, int $sizeAtRotation, int $maxBytes, string $alertEmail, int $cooldown): void
    {
        $marker = "{$path}.alerted";

        clearstatcache(true, $marker);

        if (is_file($marker) && (time() - filemtime($marker)) < $cooldown) {
            return;
        }

        try {
            @touch($marker);

            $appName = config('app.name', 'Laravel');
            $host = gethostname() ?: 'unknown-host';

            Mail::raw(
                sprintf(
                    "The log file rotated on %s (%s).\n\nPath: %s\nSize at rotation: %s\nThreshold: %s\nTime: %s\n\nThis usually means something is erroring repeatedly — worth checking the rotated file (%s.1).",
                    $appName,
                    $host,
                    $path,
                    $this->humanBytes($sizeAtRotation),
                    $this->humanBytes($maxBytes),
                    now()->toDateTimeString(),
                    $path
                ),
                function ($message) use ($alertEmail, $appName) {
                    $message->to($alertEmail)->subject("[{$appName}] Log file rotated");
                }
            );
        } catch (Throwable $e) {
            // Never let a notification failure break logging itself.
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
