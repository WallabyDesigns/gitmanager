<?php

namespace App\Support;

class InstallContext
{
    public static function isLocalInstall(?string $appUrl = null, ?string $environment = null): bool
    {
        $host = strtolower((string) parse_url((string) ($appUrl ?? config('app.url')), PHP_URL_HOST));
        if (self::isLocalHost($host)) {
            return true;
        }

        $environment = strtolower(trim((string) ($environment ?? config('app.env', app()->environment()))));

        return $host === '' && in_array($environment, ['local', 'testing'], true);
    }

    public static function isLocalHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
