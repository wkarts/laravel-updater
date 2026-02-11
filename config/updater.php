<?php

declare(strict_types=1);

return [
    'enabled' => env('UPDATER_ENABLED', true),
    'mode' => env('UPDATER_MODE', 'inplace'),
    'channel' => env('UPDATER_CHANNEL', 'stable'),

    'git' => [
        'remote' => env('UPDATER_GIT_REMOTE', 'origin'),
        'branch' => env('UPDATER_GIT_BRANCH', 'main'),
        'ff_only' => (bool) env('UPDATER_GIT_FF_ONLY', true),
    ],

    'backup' => [
        'enabled' => (bool) env('UPDATER_BACKUP_ENABLED', true),
        'keep' => (int) env('UPDATER_BACKUP_KEEP', 10),
        'path' => env('UPDATER_BACKUP_PATH', storage_path('app/updater/backups')),
        'compress' => (bool) env('UPDATER_BACKUP_COMPRESS', true),
    ],

    'snapshot' => [
        'enabled' => (bool) env('UPDATER_SNAPSHOT_ENABLED', true),
        'path' => env('UPDATER_SNAPSHOT_PATH', storage_path('app/updater/snapshots')),
        'keep' => (int) env('UPDATER_SNAPSHOT_KEEP', 10),
    ],

    'paths' => [
        'exclude_snapshot' => [
            '.env',
            'storage',
            'bootstrap/cache',
            'vendor',
            'node_modules',
            'public/uploads',
            'storage/app/updater/backups',
            'storage/app/updater/snapshots',
        ],
        'uploads_paths' => ['public/uploads'],
    ],

    'sqlite' => [
        'path' => env('UPDATER_SQLITE_PATH', storage_path('app/updater/updater.sqlite')),
    ],

    'patches' => [
        'enabled' => (bool) env('UPDATER_PATCHES_ENABLED', true),
        'path' => env('UPDATER_SQL_PATCH_PATH', database_path('updates')),
        'table' => 'updater_patches',
    ],

    'lock' => [
        'driver' => env('UPDATER_LOCK_DRIVER', 'file'),
        'timeout' => (int) env('UPDATER_LOCK_TIMEOUT', 600),
    ],

    'trigger' => [
        'driver' => env('UPDATER_TRIGGER_DRIVER', 'queue'),
    ],

    'preflight' => [
        'min_free_disk_mb' => (int) env('UPDATER_MIN_FREE_DISK_MB', 200),
        'require_clean_git' => (bool) env('UPDATER_REQUIRE_CLEAN_GIT', true),
    ],

    'build_assets' => (bool) env('UPDATER_BUILD_ASSETS', false),

    'healthcheck' => [
        'enabled' => (bool) env('UPDATER_HEALTHCHECK_ENABLED', true),
        'url' => env('UPDATER_HEALTHCHECK_URL', env('APP_URL', 'http://localhost')),
        'timeout' => (int) env('UPDATER_HEALTHCHECK_TIMEOUT', 5),
    ],

    'ui' => [
        'enabled' => (bool) env('UPDATER_UI_ENABLED', true),
        'prefix' => env('UPDATER_UI_PREFIX', '_updater'),
        'middleware' => ['web', 'auth'],
        'auth' => [
            'enabled' => (bool) env('UPDATER_UI_AUTH_ENABLED', true),
            'auto_provision_admin' => (bool) env('UPDATER_UI_AUTO_PROVISION_ADMIN', false),
            'default_email' => env('UPDATER_UI_DEFAULT_EMAIL', 'admin@admin.com'),
            'default_password' => env('UPDATER_UI_DEFAULT_PASSWORD', '123456'),
            'session_ttl' => (int) env('UPDATER_UI_SESSION_TTL', 120),
        ],
        'two_factor' => [
            'enabled' => (bool) env('UPDATER_UI_2FA_ENABLED', false),
            'required' => (bool) env('UPDATER_UI_2FA_REQUIRED', false),
            'issuer' => env('UPDATER_UI_2FA_ISSUER', 'Argws Updater'),
        ],
    ],

    'log' => [
        'enabled' => (bool) env('UPDATER_LOG_ENABLED', true),
        'channel' => env('UPDATER_LOG_CHANNEL', 'updater'),
        'path' => env('UPDATER_LOG_PATH', storage_path('logs/updater.log')),
    ],
];
