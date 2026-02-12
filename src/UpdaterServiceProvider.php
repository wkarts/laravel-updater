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
use Argws\LaravelUpdater\Http\Middleware\UpdaterAuthMiddleware;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\CacheLock;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\FileLock;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\LoggerFactory;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\Totp;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
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
        $this->app->singleton(AuthStore::class, fn () => new AuthStore($this->app->make(StateStore::class)));
        $this->app->singleton(ManagerStore::class, fn () => new ManagerStore($this->app->make(StateStore::class)));
        $this->app->singleton('updater.store', fn () => $this->app->make(StateStore::class));
        $this->app->singleton(Totp::class, fn () => new Totp());

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
                'manager_store' => $this->app->make(ManagerStore::class),
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
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('updater.auth', UpdaterAuthMiddleware::class);

        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php'),
        ], 'updater-config');

        $this->publishes([
            __DIR__ . '/../resources/assets/updater.css' => public_path('vendor/laravel-updater/updater.css'),
            __DIR__ . '/../resources/assets/updater.js' => public_path('vendor/laravel-updater/updater.js'),
        ], 'updater-assets');

        $this->syncAssetsIfNeeded();

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laravel-updater');
        $this->loadRoutesFrom(__DIR__ . '/../routes/updater.php');

        if ((bool) config('updater.ui.auth.enabled', false)) {
            $this->app->make(AuthStore::class)->ensureDefaultAdmin();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateCheckCommand::class,
                UpdateRunCommand::class,
                UpdateRollbackCommand::class,
                UpdateStatusCommand::class,
            ]);
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

    private function copyAssetIfStale(string $source, string $target): void
    {
        $sourceMtime = @filemtime($source);
        $targetMtime = @filemtime($target);

        if (!is_file($target) || ($sourceMtime !== false && $targetMtime !== false && $sourceMtime > $targetMtime)) {
            @copy($source, $target);
        }
    }

}
