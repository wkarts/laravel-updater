<?php

declare(strict_types=1);

$uiRateLimitMaxAttempts = env('UPDATER_UI_RATE_LIMIT_MAX');
if ($uiRateLimitMaxAttempts === null) {
    $uiRateLimitMaxAttempts = env('UPDATER_UI_LOGIN_MAX_ATTEMPTS', 10);
}

$uiRateLimitWindowSeconds = env('UPDATER_UI_RATE_LIMIT_WINDOW');
if ($uiRateLimitWindowSeconds === null) {
    $uiRateLimitWindowSeconds = (int) env('UPDATER_UI_LOGIN_DECAY_MINUTES', 10) * 60;
}

return [
    'enabled' => env('UPDATER_ENABLED', true),
    'mode' => env('UPDATER_MODE', 'inplace'),
    'channel' => env('UPDATER_CHANNEL', 'stable'),

    'git' => [
        'path' => env('UPDATER_GIT_PATH', base_path()),
        'remote' => env('UPDATER_GIT_REMOTE', 'origin'),
        'remote_url' => env('UPDATER_GIT_REMOTE_URL', ''),
        'branch' => env('UPDATER_GIT_BRANCH', 'main'),
        'ff_only' => (bool) env('UPDATER_GIT_FF_ONLY', true),
        'update_type' => env('UPDATER_GIT_UPDATE_TYPE', 'git_ff_only'),
        'tag' => env('UPDATER_GIT_TAG', ''),
        'auto_init' => (bool) env('UPDATER_GIT_AUTO_INIT', false),
        'default_update_mode' => env('UPDATER_GIT_DEFAULT_UPDATE_MODE', 'merge'),
        // Lista de caminhos que NÃO devem bloquear o update mesmo com working tree "dirty".
        // Aceita array via config (config/updater.php). Para ENV, use vírgula: "config/updater.php,.env,storage/".
        'dirty_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('UPDATER_GIT_DIRTY_ALLOWLIST', 'config/updater.php,.env,storage/,bootstrap/cache/'))))),
    ],

    'composer' => [
        // Pode ser: "composer", "composer2", "/usr/bin/composer" ou "/caminho/composer.phar".
        'bin' => env('UPDATER_COMPOSER_BIN', 'composer'),
    ],

    'backup' => [
        'enabled' => (bool) env('UPDATER_BACKUP_ENABLED', true),
        'keep' => (int) env('UPDATER_BACKUP_KEEP', 10),
        'path' => env('UPDATER_BACKUP_PATH', storage_path('app/updater/backups')),
        'compress' => (bool) env('UPDATER_BACKUP_COMPRESS', true),
        'upload_disk' => env('UPDATER_BACKUP_UPLOAD_DISK', ''),
        'upload_prefix' => env('UPDATER_BACKUP_UPLOAD_PREFIX', 'updater/backups'),
        'mysqldump_binary' => env('UPDATER_MYSQLDUMP_BINARY', ''),
        'mysql_binary' => env('UPDATER_MYSQL_BINARY', ''),
        'pg_dump_binary' => env('UPDATER_PG_DUMP_BINARY', ''),
        'pg_restore_binary' => env('UPDATER_PG_RESTORE_BINARY', ''),
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
        'driver' => env('UPDATER_TRIGGER_DRIVER', 'auto'),
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

    'sources' => [
        'allow_multiple' => (bool) env('UPDATER_SOURCES_ALLOW_MULTIPLE', false),
    ],

    'ui' => [
        'enabled' => (bool) env('UPDATER_UI_ENABLED', true),
        'prefix' => env('UPDATER_UI_PREFIX', '_updater'),
        'middleware' => ['web', 'auth'],
        'force_sync' => (bool) env('UPDATER_UI_FORCE_SYNC', false),
        'auth' => [
            'enabled' => (bool) env('UPDATER_UI_AUTH_ENABLED', false),
            'auto_provision_admin' => (bool) env('UPDATER_UI_AUTO_PROVISION_ADMIN', true),
            'default_email' => env('UPDATER_UI_DEFAULT_EMAIL', 'admin@admin.com'),
            'default_password' => env('UPDATER_UI_DEFAULT_PASSWORD', '123456'),
            'default_name' => env('UPDATER_UI_DEFAULT_NAME', 'Admin'),
            'session_ttl_minutes' => (int) env('UPDATER_UI_SESSION_TTL', 120),
            'rate_limit' => [
                'max_attempts' => (int) $uiRateLimitMaxAttempts,
                'window_seconds' => (int) $uiRateLimitWindowSeconds,
            ],
            '2fa' => [
                'enabled' => (bool) env('UPDATER_UI_2FA_ENABLED', true),
                'required' => (bool) env('UPDATER_UI_2FA_REQUIRED', false),
                'issuer' => env('UPDATER_UI_2FA_ISSUER', 'Argws Updater'),
            ],
        ],
    ],


    'notify' => [
        'enabled' => (bool) env('UPDATER_NOTIFY_ENABLED', false),
        'to' => env('UPDATER_NOTIFY_TO', env('UPDATER_REPORT_TO', '')),
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


    'maintenance' => [
        // View used by `php artisan down --render=...` during update.
        // Default uses the package view to avoid CLI-only globals (REQUEST_URI) issues.
        'render_view' => env('UPDATER_MAINTENANCE_VIEW', 'laravel-updater::maintenance'),

        // Basic message defaults used by the package maintenance view.
        'default_title' => env('UPDATER_MAINTENANCE_TITLE', 'Atualização em andamento'),
        'default_message' => env('UPDATER_MAINTENANCE_MESSAGE', 'Estamos atualizando o sistema. Volte em alguns minutos.'),
        'default_footer' => env('UPDATER_MAINTENANCE_FOOTER', 'Obrigado pela compreensão.'),
    ],

];
