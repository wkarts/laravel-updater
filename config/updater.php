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

    'lock' => [
        'driver' => env('UPDATER_LOCK_DRIVER', 'file'),
        'timeout' => (int) env('UPDATER_LOCK_TIMEOUT', 600),
    ],

    'build_assets' => (bool) env('UPDATER_BUILD_ASSETS', false),

    'healthcheck' => [
        'enabled' => (bool) env('UPDATER_HEALTHCHECK_ENABLED', true),
        'url' => env('UPDATER_HEALTHCHECK_URL', env('APP_URL', 'http://localhost')),
        'timeout' => (int) env('UPDATER_HEALTHCHECK_TIMEOUT', 5),
    ],

    'sql_patch_path' => env('UPDATER_SQL_PATCH_PATH', database_path('updates')),

    'log' => [
        'enabled' => (bool) env('UPDATER_LOG_ENABLED', true),
        'channel' => env('UPDATER_LOG_CHANNEL', 'updater'),
        'path' => env('UPDATER_LOG_PATH', storage_path('logs/updater.log')),
    ],
];
