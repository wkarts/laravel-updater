<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater;

use Argws\LaravelUpdater\Commands\UpdateBackupCommand;
use Argws\LaravelUpdater\Commands\UpdateBackupUploadCommand;
use Argws\LaravelUpdater\Commands\UpdateCheckCommand;
use Argws\LaravelUpdater\Commands\UpdateEnvSyncCommand;
use Argws\LaravelUpdater\Commands\UpdateGitMaintainCommand;
use Argws\LaravelUpdater\Commands\UpdateGitSizeCommand;
use Argws\LaravelUpdater\Commands\UpdateNotifyCommand;
use Argws\LaravelUpdater\Commands\UpdateRollbackCommand;
use Argws\LaravelUpdater\Commands\UpdateRunCommand;
use Argws\LaravelUpdater\Commands\UpdateStatusCommand;
use Argws\LaravelUpdater\Commands\UpdaterMigrateCommand;
use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Drivers\MysqlBackupDriver;
use Argws\LaravelUpdater\Drivers\PgsqlBackupDriver;
use Argws\LaravelUpdater\Http\Middleware\UpdaterAuthMiddleware;
use Argws\LaravelUpdater\Http\Middleware\UpdaterAuthorizeMiddleware;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Migration\IdempotentMigrationService;
use Argws\LaravelUpdater\Migration\MigrationDriftDetector;
use Argws\LaravelUpdater\Migration\MigrationFailureClassifier;
use Argws\LaravelUpdater\Migration\MigrationReconciler;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\BackupCloudUploader;
use Argws\LaravelUpdater\Support\CacheLock;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\FileLock;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\GitMaintenance;
use Argws\LaravelUpdater\Support\LoggerFactory;
use Argws\LaravelUpdater\Support\MaintenanceMode;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\Totp;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Argws\LaravelUpdater\Support\UiPermission;
use Argws\LaravelUpdater\Support\UpdaterLockTools;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Psr\Log\LoggerInterface;

class UpdaterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/updater.php', 'updater');

        $this->app->singleton(ShellRunner::class, fn () => new ShellRunner());
        $this->app->singleton(FileManager::class, fn () => new FileManager(new Filesystem()));
        $this->app->singleton(ArchiveManager::class, fn () => new ArchiveManager());
        $this->app->singleton(EnvironmentDetector::class, fn () => new EnvironmentDetector());
        $this->app->singleton(StateStore::class, function () {
            $store = new StateStore((string) config('updater.sqlite.path'));
            $store->ensureSchema();
            return $store;
        });
        $this->app->singleton(AuthStore::class, fn () => new AuthStore($this->app->make(StateStore::class)));
        $this->app->singleton(ManagerStore::class, fn () => new ManagerStore($this->app->make(StateStore::class)));
        $this->app->singleton(BackupCloudUploader::class, fn () => new BackupCloudUploader());
        $this->app->singleton(MaintenanceMode::class, fn () => new MaintenanceMode());
        $this->app->singleton('updater.store', fn () => $this->app->make(StateStore::class));
        $this->app->singleton(Totp::class, fn () => new Totp());
        $this->app->singleton(UiPermission::class, fn () => new UiPermission());
        $this->app->singleton(UpdaterLockTools::class, fn () => new UpdaterLockTools());

        $this->app->singleton(LockInterface::class, function () {
            if ((string) config('updater.lock.driver', 'file') === 'cache') {
                return new CacheLock();
            }

            return new FileLock(storage_path('framework/cache/updater.lock'));
        });

        $this->app->singleton(CodeDriverInterface::class, function () {
            return new GitDriver($this->app->make(ShellRunner::class), config('updater.git'));
        });

        $this->app->singleton(BackupDriverInterface::class, function () {
            $default = config('database.default');
            $dbConfig = config("database.connections.{$default}", []);
            $shell = $this->app->make(ShellRunner::class);
            $files = $this->app->make(FileManager::class);
            $backupConfig = config('updater.backup');

            return ($dbConfig['driver'] ?? '') === 'pgsql'
                ? new PgsqlBackupDriver($shell, $files, $dbConfig, $backupConfig)
                : new MysqlBackupDriver($shell, $files, $dbConfig, $backupConfig);
        });

        $this->app->singleton(LoggerInterface::class, function () {
            return LoggerFactory::make(config('updater.log'));
        });

        $this->app->singleton(PreflightChecker::class, function () {
            return new PreflightChecker(
                $this->app->make(ShellRunner::class),
                $this->app->make(CodeDriverInterface::class),
                config('updater.preflight')
            );
        });

        $this->app->singleton(RunReportMailer::class, fn () => new RunReportMailer());

        $this->app->singleton(MigrationFailureClassifier::class, fn () => new MigrationFailureClassifier());
        $this->app->singleton(MigrationDriftDetector::class, fn () => new MigrationDriftDetector($this->app['db']));
        $this->app->singleton(MigrationReconciler::class, fn () => new MigrationReconciler($this->app['db']));
        $this->app->singleton(IdempotentMigrationService::class, function () {
            return new IdempotentMigrationService(
                $this->app->make('migrator'),
                $this->app->make(MigrationFailureClassifier::class),
                $this->app->make(MigrationReconciler::class),
                $this->app->make(MigrationDriftDetector::class)
            );
        });

        $this->app->singleton(TriggerDispatcher::class, function () {
            return new TriggerDispatcher((string) config('updater.trigger.driver', 'queue'), $this->app->make(StateStore::class));
        });

        
        $this->app->singleton(GitMaintenance::class, function ($app) {
            return new GitMaintenance($app->make(ShellRunner::class), (array) config('updater'));
        });

$this->app->singleton(UpdaterKernel::class, function () {
            $services = [
                'lock' => $this->app->make(LockInterface::class),
                'shell' => $this->app->make(ShellRunner::class),
                'backup' => $this->app->make(BackupDriverInterface::class),
                'files' => $this->app->make(FileManager::class),
                'archive' => $this->app->make(ArchiveManager::class),
                'git_maintenance' => $this->app->make(GitMaintenance::class),
                'code' => $this->app->make(CodeDriverInterface::class),
                'logger' => $this->app->make(LoggerInterface::class),
                'store' => $this->app->make(StateStore::class),
                'manager_store' => $this->app->make(ManagerStore::class),
                'maintenance_mode' => $this->app->make(MaintenanceMode::class),
            ];

            $kernel = new UpdaterKernel(
                $this->app->make(EnvironmentDetector::class),
                UpdaterKernel::makePipeline($services),
                $services['code'],
                $this->app->make(PreflightChecker::class),
                $services['store'],
                $services['logger'],
                $this->app->make(RunReportMailer::class)
            );

            $this->app->instance('updater.kernel', $kernel);

            return $kernel;
        });

        $this->app->alias(UpdaterKernel::class, 'updater.kernel');
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('updater.auth', UpdaterAuthMiddleware::class);
        $router->aliasMiddleware('updater.authorize', UpdaterAuthorizeMiddleware::class);

        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php'),
        ], 'updater-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/laravel-updater'),
        ], 'updater-views');

        $this->publishes([
            __DIR__ . '/../resources/assets/updater.css' => public_path('vendor/laravel-updater/updater.css'),
            __DIR__ . '/../resources/assets/updater.js' => public_path('vendor/laravel-updater/updater.js'),
        ], 'updater-assets');

        $this->syncAssetsIfNeeded();
        $this->syncPublishedResourcesIfNeeded();
        $this->runVendorPublishIfConfigured();

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laravel-updater');
        $this->loadRoutesFrom(__DIR__ . '/../routes/updater.php');

        if ((bool) config('updater.ui.auth.enabled', false)) {
            $this->app->make(AuthStore::class)->ensureDefaultAdmin();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateBackupCommand::class,
                UpdateBackupUploadCommand::class,
                UpdateCheckCommand::class,
                UpdateRunCommand::class,
                UpdateRollbackCommand::class,
                UpdateStatusCommand::class,
                UpdateNotifyCommand::class,
                UpdateEnvSyncCommand::class,
                UpdaterMigrateCommand::class,
                UpdateGitMaintainCommand::class,
                UpdateGitSizeCommand::class,
            ]);
// Agenda manutenção do .git automaticamente (se o projeto executar schedule:run)
$this->app->booted(function () {
    try {
        $cfg = (array) config('updater.git_maintenance', []);
        $enabled = (bool) ($cfg['enabled'] ?? true);
        $scheduleEnabled = (bool) ($cfg['schedule_enabled'] ?? true);
        if (!$enabled || !$scheduleEnabled) {
            return;
        }

        /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $frequency = (string) ($cfg['schedule_frequency'] ?? 'daily');
        $event = $schedule->command('updater:git:maintain --reason=schedule')
            ->withoutOverlapping()
            ->onOneServer();

        // Frequências suportadas: daily|weekly|hourly
        if ($frequency === 'hourly') {
            $event->hourly();
        } elseif ($frequency === 'weekly') {
            $event->weekly();
        } else {
            $event->daily();
        }
    } catch (\Throwable) {
        // Não falha boot se scheduler não estiver disponível
    }
});

        }
    }
    private function syncAssetsIfNeeded(): void
    {
        $targetDir = public_path('vendor/laravel-updater');
        $sourceCss = __DIR__ . '/../resources/assets/updater.css';
        $sourceJs = __DIR__ . '/../resources/assets/updater.js';
        $targetCss = $targetDir . '/updater.css';
        $targetJs = $targetDir . '/updater.js';

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $this->copyAssetIfStale($sourceCss, $targetCss);
        $this->copyAssetIfStale($sourceJs, $targetJs);
    }


    private function syncPublishedResourcesIfNeeded(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        if (!(bool) config('updater.auto_publish.enabled', true)) {
            return;
        }

        if ((bool) config('updater.auto_publish.config', true)) {
            $this->copyAssetIfStale(__DIR__ . '/../config/updater.php', config_path('updater.php'));
        }

        if ((bool) config('updater.auto_publish.views', true)) {
            $this->copyDirectoryIfStale(__DIR__ . '/../resources/views', resource_path('views/vendor/laravel-updater'));
        }
    }


    private function runVendorPublishIfConfigured(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        if (!(bool) config('updater.auto_publish.run_vendor_publish', true)) {
            return;
        }

        if ((bool) $this->app->bound('updater.vendor_publish_ran')) {
            return;
        }

        $this->app->instance('updater.vendor_publish_ran', true);

        try {
            Artisan::call('vendor:publish', ['--tag' => 'updater-views', '--force' => true]);
            Artisan::call('vendor:publish', ['--tag' => 'updater-config', '--force' => true]);
        } catch (\Throwable $e) {
            // Não bloquear boot em caso de falha no publish automático.
        }
    }

    private function copyDirectoryIfStale(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($sourceDir)) {
            return;
        }

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $entries = @scandir($sourceDir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $sourceDir . '/' . $entry;
            $target = $targetDir . '/' . $entry;

            if (is_dir($source)) {
                $this->copyDirectoryIfStale($source, $target);
                continue;
            }

            $this->copyAssetIfStale($source, $target);
        }
    }

    private function copyAssetIfStale(string $source, string $target): void
    {
        $sourceMtime = @filemtime($source);
        $targetMtime = @filemtime($target);

        if (!is_file($target) || ($sourceMtime !== false && $targetMtime !== false && $sourceMtime > $targetMtime)) {
            @copy($source, $target);
        }
    }

}
