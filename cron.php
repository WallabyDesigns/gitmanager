<?php

// Re-exec with the app-configured PHP binary if the env specifies one.
// This lets the cron line use the system `php` while still ensuring the
// correct runtime (with all required extensions) handles the actual work.
$configured = gwmResolvePhpBinary(__DIR__);
if ($configured !== null) {
    $resolvedConfigured = realpath($configured);
    $resolvedCurrent    = realpath(PHP_BINARY);
    if ($resolvedConfigured !== false && $resolvedConfigured !== $resolvedCurrent) {
        passthru(escapeshellarg($configured).' '.escapeshellarg(__FILE__), $exitCode);
        exit($exitCode ?? 1);
    }
}

define('LARAVEL_START', microtime(true));

require_once __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$status = $app->handleCommand(
    new Symfony\Component\Console\Input\ArgvInput(['artisan', 'scheduler:run'])
);

exit($status);

function gwmResolvePhpBinary(string $dir): ?string
{
    $envFile = $dir.'/.env';
    if (! is_file($envFile)) {
        return null;
    }

    $content = @file_get_contents($envFile);

    // Match the same env-var precedence as config/gitmanager.php
    $keys = ['GWM_PHP_BINARY', 'GWM_PHP_PATH', 'GPM_PHP_BINARY', 'GPM_PHP_PATH'];
    foreach ($keys as $key) {
        if ($content !== false && preg_match('/^'.preg_quote($key, '/').'[ \t]*=[ \t]*(.+)$/m', $content, $m)) {
            $value = trim($m[1], " \t\r\n\"'");
            if ($value !== '' && $value !== 'php' && is_executable($value)) {
                return $value;
            }
        }
    }

    return null;
}
