<?php

return [
    'git_binary' => env('GPM_GIT_BINARY', 'git'),

    'self_update' => [
        // Disable by default outside production to avoid clobbering local dev changes.
        'enabled' => env('GPM_SELF_UPDATE_ENABLED', env('APP_ENV', 'production') === 'production'),
    ],

    'preview' => [
        'path' => env('GPM_PREVIEW_PATH', storage_path('app/previews')),
        'base_url' => env('GPM_PREVIEW_BASE_URL', ''),
    ],
];
