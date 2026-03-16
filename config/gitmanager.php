<?php

return [
    'git_binary' => env('GPM_GIT_BINARY', 'git'),
    'composer_binary' => env('GPM_COMPOSER_BINARY', 'composer'),
    'npm_binary' => env('GPM_NPM_BINARY', 'npm'),
    'php_binary' => env('GPM_PHP_BINARY', env('GPM_PHP_PATH', 'php')),
    'process_path' => env('GPM_PROCESS_PATH', ''),
    'askpass_dir' => env('GPM_ASKPASS_DIR', ''),

    'self_update' => [
        // Disable by default outside production to avoid clobbering local dev changes.
        'enabled' => env('GPM_SELF_UPDATE_ENABLED', env('APP_ENV', 'production') === 'production'),
    ],

    'preview' => [
        'path' => env('GPM_PREVIEW_PATH', storage_path('app/previews')),
        'base_url' => env('GPM_PREVIEW_BASE_URL', ''),
    ],
];
