#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(2);
}

$url = $argv[1] ?? '';
$timeout = isset($argv[2]) ? max(1, (int) $argv[2]) : 10;
$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

if ($url === '' || ! in_array($scheme, ['http', 'https'], true)) {
    fwrite(STDERR, "Usage: gwm-http-status.php <http-url> [timeout]\n");
    exit(2);
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 1,
        'max_redirects' => 5,
        'header' => implode("\r\n", [
            'User-Agent: Git Web Manager Health Check',
            'Accept: */*',
            'Connection: close',
        ]),
    ],
]);

$headers = [];
set_error_handler(static function (): bool {
    return true;
});

try {
    $result = file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
} finally {
    restore_error_handler();
}

$status = null;
foreach ($headers as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string) $header, $matches)) {
        $status = (int) $matches[1];
    }
}

if ($status === null) {
    fwrite(STDERR, $result === false ? "HTTP request failed before a response was received.\n" : "HTTP response status was not available.\n");
    exit(1);
}

echo "HTTP/1.1 {$status}\n";
exit($status >= 200 && $status < 400 ? 0 : 1);
