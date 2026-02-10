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
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\FileLock;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\LoggerFactory;
use Argws\LaravelUpdater\Support\ShellRunner;
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

        $this->app->singleton(LockInterface::class, function () {
            $lockPath = storage_path('framework/cache/updater.lock');
            return new FileLock($lockPath);
        });

        $this->app->singleton(CodeDriverInterface::class, function () {
            return new GitDriver($this->app->make(ShellRunner::class), config('updater.git'));
        });

        $this->app->singleton(BackupDriverInterface::class, function () {
            $default = config('database.default');
            $dbConfig = config("database.connections.{$default}", []);
            return ($dbConfig['driver'] ?? '') === 'pgsql'
                ? new PgsqlBackupDriver($this->app->make(ShellRunner::class), $dbConfig, config('updater.backup'))
                : new MysqlBackupDriver($this->app->make(ShellRunner::class), $dbConfig, config('updater.backup'));
        });

        $this->app->singleton(LoggerInterface::class, function () {
            return LoggerFactory::make(config('updater.log'));
        });

        $this->app->singleton(UpdaterKernel::class, function () {
            $services = [
                'lock' => $this->app->make(LockInterface::class),
                'shell' => $this->app->make(ShellRunner::class),
                'backup' => $this->app->make(BackupDriverInterface::class),
                'files' => $this->app->make(FileManager::class),
                'code' => $this->app->make(CodeDriverInterface::class),
                'logger' => $this->app->make(LoggerInterface::class),
            ];

            return new UpdaterKernel(
                $this->app->make(EnvironmentDetector::class),
                UpdaterKernel::makePipeline($services),
                $services['code'],
                $services['logger']
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php'),
        ], 'updater-config');

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
