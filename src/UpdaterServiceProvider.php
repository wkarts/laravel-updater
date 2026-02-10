<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater;

use Argws\LaravelUpdater\Commands\UpdateCheckCommand;
use Argws\LaravelUpdater\Commands\UpdateRollbackCommand;
use Argws\LaravelUpdater\Commands\UpdateRunCommand;
use Argws\LaravelUpdater\Commands\UpdateStatusCommand;
use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Drivers\MysqlBackupDriver;
use Argws\LaravelUpdater\Drivers\PgsqlBackupDriver;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\CacheLock;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\FileLock;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\LoggerFactory;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class UpdaterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/updater.php', 'updater');

        $this->app->singleton(ShellRunner::class, fn () => new ShellRunner());
        $this->app->singleton(FileManager::class, fn () => new FileManager(new Filesystem()));
        $this->app->singleton(EnvironmentDetector::class, fn () => new EnvironmentDetector());
        $this->app->singleton(StateStore::class, function () {
            $store = new StateStore((string) config('updater.sqlite.path'));
            $store->ensureSchema();
            return $store;
        });

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

        $this->app->singleton(TriggerDispatcher::class, function () {
            return new TriggerDispatcher((string) config('updater.trigger.driver', 'queue'), $this->app->make(StateStore::class));
        });

        $this->app->singleton(UpdaterKernel::class, function () {
            $services = [
                'lock' => $this->app->make(LockInterface::class),
                'shell' => $this->app->make(ShellRunner::class),
                'backup' => $this->app->make(BackupDriverInterface::class),
                'files' => $this->app->make(FileManager::class),
                'code' => $this->app->make(CodeDriverInterface::class),
                'logger' => $this->app->make(LoggerInterface::class),
                'store' => $this->app->make(StateStore::class),
            ];

            return new UpdaterKernel(
                $this->app->make(EnvironmentDetector::class),
                UpdaterKernel::makePipeline($services),
                $services['code'],
                $this->app->make(PreflightChecker::class),
                $services['store'],
                $services['logger']
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php'),
        ], 'updater-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laravel-updater');
        $this->loadRoutesFrom(__DIR__ . '/../routes/updater.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateCheckCommand::class,
                UpdateRunCommand::class,
                UpdateRollbackCommand::class,
                UpdateStatusCommand::class,
            ]);
        }
    }
}
