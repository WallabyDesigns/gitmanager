<?php

return [
    'git_binary' => env('GWM_GIT_BINARY', env('GPM_GIT_BINARY', 'git')),
    'composer_binary' => env('GWM_COMPOSER_BINARY', env('GPM_COMPOSER_BINARY', 'composer')),
    'npm_binary' => env('GWM_NPM_BINARY', env('GPM_NPM_BINARY', 'npm')),
    'php_binary' => env('GWM_PHP_BINARY', env('GWM_PHP_PATH', env('GPM_PHP_BINARY', env('GPM_PHP_PATH', 'php')))),
    'process_path' => env('GWM_PROCESS_PATH', env('GPM_PROCESS_PATH', '')),
    'process_timeout' => env('GWM_PROCESS_TIMEOUT', 900),
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
    'ftp' => [
        'workspace_path' => env('GWM_FTP_WORKSPACE_PATH', storage_path('app/ftp-workspaces')),
    ],
    'ssh' => [
        'binary' => env('GWM_SSH_BINARY', 'ssh'),
        'pass_binary' => env('GWM_SSH_PASS_BINARY', ''),
        'known_hosts' => env('GWM_SSH_KNOWN_HOSTS', storage_path('app/ssh_known_hosts')),
        'strict_host_key_checking' => env('GWM_SSH_STRICT_HOST_KEY', 'accept-new'),
        'key_path' => env('GWM_SSH_KEY_PATH', ''),
    ],
    'docker' => [
        'binary' => env('GWM_DOCKER_BINARY', 'docker'),
    ],
    'kubernetes' => [
        'kubectl_binary' => env('GWM_KUBECTL_BINARY', 'kubectl'),
    ],
    'nextjs_ssh_runtime_port' => env('GWM_NEXTJS_SSH_RUNTIME_PORT', 3000),
    'sudo' => [
        'enabled' => env('GWM_SUDO_ENABLED', false),
        'binary' => env('GWM_SUDO_BINARY', 'sudo'),
    ],
    'scheduler' => [
        'stale_seconds' => env('GWM_SCHEDULER_STALE_SECONDS', 120),
    ],
    'license' => [
        // NOTE:
        // These env fallbacks are only honored in local/testing by LicenseService.
        // Production verification settings should come from enterprise runtime config.
        'verify_url' => env('GWM_LICENSE_VERIFY_URL', ''),
        'timeout' => env('GWM_LICENSE_TIMEOUT', 10),
        'cache_seconds' => env('GWM_LICENSE_CACHE_SECONDS', 900),
        'strict_ip' => env('GWM_LICENSE_STRICT_IP', true),
        'public_ip' => env('GWM_LICENSE_PUBLIC_IP', ''),
        'verify_signature' => env('GWM_LICENSE_VERIFY_SIGNATURE', true),
        'response_signing_secret' => env('GWM_LICENSE_RESPONSE_SIGNING_SECRET', ''),
        'request_signing_secret' => env('GWM_LICENSE_REQUEST_SIGNING_SECRET', ''),
        // Local/testing only escape hatch for self-signed or missing local CA chains.
        // Never enable this in production.
        'allow_insecure_local_tls' => env('GWM_LICENSE_ALLOW_INSECURE_LOCAL_TLS', false),
    ],
    'support' => [
        'enabled' => env('GWM_SUPPORT_ENABLED', true),
        // Optional local/testing override. In production this is derived from license verify host:
        // https://<license-domain>/api/v1/support
        'api_url' => env('GWM_SUPPORT_API_URL', ''),
        'timeout' => env('GWM_SUPPORT_TIMEOUT', 15),
        // Local/testing TLS bypass for support requests reuses the license repair setting:
        // system.license.allow_insecure_local_tls / GWM_LICENSE_ALLOW_INSECURE_LOCAL_TLS
    ],
    'enterprise' => [
        'package_name' => 'wallabydesigns/gitmanager-enterprise',
        'check_updates' => true,
    ],
];
