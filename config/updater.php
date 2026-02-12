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
        'update_type' => env('UPDATER_GIT_UPDATE_TYPE', 'git_ff_only'),
        'tag' => env('UPDATER_GIT_TAG', ''),
    ],

    'backup' => [
        'enabled' => (bool) env('UPDATER_BACKUP_ENABLED', true),
        'keep' => (int) env('UPDATER_BACKUP_KEEP', 10),
        'path' => env('UPDATER_BACKUP_PATH', storage_path('app/updater/backups')),
        'compress' => (bool) env('UPDATER_BACKUP_COMPRESS', true),
        'upload_disk' => env('UPDATER_BACKUP_UPLOAD_DISK', ''),
        'upload_prefix' => env('UPDATER_BACKUP_UPLOAD_PREFIX', 'updater/backups'),
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


    'app' => [
        'name' => env('UPDATER_APP_NAME', 'APP_NAME'),
        'sufix_name' => env('UPDATER_APP_SUFIX_NAME', 'APP_SUFIX_NAME'),
        'desc' => env('UPDATER_APP_DESC', 'APP_DESC'),
    ],

    'branding' => [
        'max_upload_kb' => (int) env('UPDATER_BRANDING_MAX_UPLOAD_KB', 1024),
    ],

    'sync_token' => env('UPDATER_SYNC_TOKEN', ''),

    'ui' => [
        'enabled' => (bool) env('UPDATER_UI_ENABLED', true),
        'prefix' => env('UPDATER_UI_PREFIX', '_updater'),
        'middleware' => ['web', 'auth'],
        'auth' => [
            'enabled' => (bool) env('UPDATER_UI_AUTH_ENABLED', false),
            'auto_provision_admin' => (bool) env('UPDATER_UI_AUTO_PROVISION_ADMIN', true),
            'default_email' => env('UPDATER_UI_DEFAULT_EMAIL', 'admin@admin.com'),
            'default_password' => env('UPDATER_UI_DEFAULT_PASSWORD', '123456'),
            'default_name' => env('UPDATER_UI_DEFAULT_NAME', 'Admin'),
            'session_ttl_minutes' => (int) env('UPDATER_UI_SESSION_TTL', 120),
            'rate_limit' => [
                'max_attempts' => (int) env('UPDATER_UI_RATE_LIMIT_MAX', 10),
                'window_seconds' => (int) env('UPDATER_UI_RATE_LIMIT_WINDOW', 600),
            ],
            '2fa' => [
                'enabled' => (bool) env('UPDATER_UI_2FA_ENABLED', true),
                'required' => (bool) env('UPDATER_UI_2FA_REQUIRED', false),
                'issuer' => env('UPDATER_UI_2FA_ISSUER', 'Argws Updater'),
            ],
        ],
    ],

    'report' => [
        'enabled' => (bool) env('UPDATER_REPORT_ENABLED', false),
        'on' => env('UPDATER_REPORT_ON', 'failure'),
        'to' => env('UPDATER_REPORT_TO', ''),
        'subject_prefix' => env('UPDATER_REPORT_SUBJECT_PREFIX', '[Updater]'),
        'attach_logs' => (bool) env('UPDATER_REPORT_ATTACH_LOGS', false),
    ],

    'log' => [
        'enabled' => (bool) env('UPDATER_LOG_ENABLED', true),
        'channel' => env('UPDATER_LOG_CHANNEL', 'updater'),
        'path' => env('UPDATER_LOG_PATH', storage_path('logs/updater.log')),
    ],
];
