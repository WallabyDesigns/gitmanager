<?php

return [
    'git_binary' => env('GWM_GIT_BINARY', env('GPM_GIT_BINARY', 'git')),
    'composer_binary' => env('GWM_COMPOSER_BINARY', env('GPM_COMPOSER_BINARY', 'composer')),
    'npm_binary' => env('GWM_NPM_BINARY', env('GPM_NPM_BINARY', 'npm')),
    'php_binary' => env('GWM_PHP_BINARY', env('GWM_PHP_PATH', env('GPM_PHP_BINARY', env('GPM_PHP_PATH', 'php')))),
    'process_path' => env('GWM_PROCESS_PATH', env('GPM_PROCESS_PATH', '')),
    'askpass_dir' => env('GWM_ASKPASS_DIR', env('GPM_ASKPASS_DIR', '')),

    'self_update' => [
        // Disable by default outside production to avoid clobbering local dev changes.
        'enabled' => env('GWM_SELF_UPDATE_ENABLED', env('GPM_SELF_UPDATE_ENABLED', env('APP_ENV', 'production') === 'production')),
        'exclude_paths' => array_values(array_filter(array_map('trim', explode(',', env('GWM_SELF_UPDATE_EXCLUDE_PATHS', env('GPM_SELF_UPDATE_EXCLUDE_PATHS', 'docs')))))),
    ],

    'preview' => [
        'path' => env('GWM_PREVIEW_PATH', env('GPM_PREVIEW_PATH', storage_path('app/previews'))),
        'base_url' => env('GWM_PREVIEW_BASE_URL', env('GPM_PREVIEW_BASE_URL', '')),
    ],

    'deploy_queue' => [
        'enabled' => env('GWM_DEPLOY_QUEUE_ENABLED', true),
        'stale_seconds' => env('GWM_DEPLOY_QUEUE_STALE_SECONDS', 900),
    ],
    'deployments' => [
        'stale_seconds' => env('GWM_DEPLOYMENT_STALE_SECONDS', 3600),
    ],
    'deploy_staging' => [
        'enabled' => env('GWM_DEPLOY_STAGING_ENABLED', true),
        'path' => env('GWM_DEPLOY_STAGING_PATH', storage_path('app/deploy-staging')),
    ],
    'ssh' => [
        'binary' => env('GWM_SSH_BINARY', 'ssh'),
        'pass_binary' => env('GWM_SSH_PASS_BINARY', ''),
        'known_hosts' => env('GWM_SSH_KNOWN_HOSTS', storage_path('app/ssh_known_hosts')),
        'strict_host_key_checking' => env('GWM_SSH_STRICT_HOST_KEY', 'accept-new'),
        'key_path' => env('GWM_SSH_KEY_PATH', ''),
    ],
    'sudo' => [
        'enabled' => env('GWM_SUDO_ENABLED', false),
        'binary' => env('GWM_SUDO_BINARY', 'sudo'),
    ],
    'scheduler' => [
        'stale_seconds' => env('GWM_SCHEDULER_STALE_SECONDS', 120),
    ],
];
