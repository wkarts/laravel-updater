<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Argws\LaravelUpdater\Pipeline\Steps\LockStep;
use Argws\LaravelUpdater\Pipeline\Steps\CacheClearStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOffStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOnStep;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\OwnedNativeMaintenanceMode;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateMaintenanceState;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
use Argws\LaravelUpdater\Support\UpdaterLockTools;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Contracts\Foundation\MaintenanceMode as NativeMaintenanceContract;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Orchestra\Testbench\TestCase;
use Psr\Log\NullLogger;

final class OptionalUpdateMaintenanceTest extends TestCase
{
    private string $temporaryStorage;
    private MaintenanceRecordingShell $shell;
    private MaintenanceRecordingLock $lock;
    private StateStore $store;
    private ManagerStore $manager;

    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('m', 32)));
        $app['config']->set('app.maintenance.driver', 'file');
        $app['config']->set('updater.maintenance.soft_enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/customer-service', fn () => response('Customer application available', 200));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryStorage = sys_get_temp_dir() . '/updater-optional-maintenance-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryStorage . '/framework', 0775, true);
        $this->app->useStoragePath($this->temporaryStorage);
        $this->store = app(StateStore::class);
        $this->store->ensureSchema();
        $this->manager = app(ManagerStore::class);
        // These lifecycle fixtures explicitly opt into automatic maintenance.
        // New-install defaults are tested without this saved preference.
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 1]);
        $this->shell = new MaintenanceRecordingShell();
        $this->lock = new MaintenanceRecordingLock();
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryStorage)) {
            app()->maintenanceMode()->deactivate();
            (new Filesystem())->deleteDirectory($this->temporaryStorage);
        }
        parent::tearDown();
    }

    public function testPersistedUncheckedTogglePreventsAllMaintenanceMutations(): void
    {
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 0]);
        $context = ['run_id' => $this->store->createRun([])];
        self::assertFalse($this->on()->shouldRun($context));
        $this->on()->handle($context);
        $this->off()->handle($context);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertFalse(app()->isDownForMaintenance());
        self::assertSame([], $this->shell->commands);
        self::assertSame(1, $this->lock->releases);
    }

    public function testEnvironmentConfigFalseAndFalseStringAreRespectedWithoutSavedOverride(): void
    {
        $this->manager->saveBranding([]);
        foreach ([false, 0, '0', 'false'] as $value) {
            config(['updater.maintenance.enter_on_update_start' => $value]);
            self::assertFalse($this->on()->shouldRun([]));
            $context = [];
            $this->on()->handle($context);
        }
        self::assertSame([], $this->shell->commands);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
    }

    public function testLiveUpdatePipelineRunsWithMaintenanceDisabledAndReleasesItsLock(): void
    {
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 0]);
        $context = ['run_id' => $this->store->createRun([])];
        $operation = new class implements PipelineStepInterface {
            public function name(): string { return 'fixture_live_update'; }
            public function shouldRun(array $context): bool { return true; }
            public function handle(array &$context): void
            {
                if (Cache::has(SoftMaintenanceMiddleware::CACHE_KEY) || app()->isDownForMaintenance()) {
                    throw new \RuntimeException('Live update must leave client requests available.');
                }
                $context['updated'] = true;
            }
            public function rollback(array &$context): void {}
        };
        (new UpdatePipeline([
            new LockStep($this->lock, 30), $this->on(), $operation, $this->off(),
        ], new NullLogger(), $this->store))->run($context);
        self::assertTrue($context['updated']);
        self::assertSame(1, $this->lock->releases);
        self::assertSame([], $this->shell->commands);
    }

    public function testHostHttpRequestsStayAvailableDuringUpdateWithMaintenanceDisabled(): void
    {
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 0]);
        $this->assertHostHttpStatusDuringPipeline(200);
        self::assertSame([], $this->shell->commands);
    }

    public function testHostHttpRequestsReturn503OnlyWhileExplicitlyEnabledMaintenanceIsActive(): void
    {
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 1]);
        $this->assertHostHttpStatusDuringPipeline(503);
        self::assertSame([], $this->shell->commands);
    }

    public function testPersistedEnabledToggleOverridesEnvironmentDefaultAndSoftCleanupDoesNotRunArtisanUp(): void
    {
        config(['updater.maintenance.enter_on_update_start' => false]);
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 1]);
        $context = ['run_id' => $this->store->createRun([])];
        self::assertTrue($this->on()->shouldRun($context));
        $this->on()->handle($context);
        self::assertSame($context['maintenance_ownership_id'], Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertTrue($context['maintenance_owned']);
        self::assertSame('soft', $context['maintenance_mode']);
        $this->off()->handle($context);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertSame([], $this->shell->commands);
        self::assertSame(1, $this->lock->releases);
    }

    public function testNativeMaintenanceIsEnteredAndReleasedOnlyWhenEnabledAndOwned(): void
    {
        config(['updater.maintenance.soft_enabled' => false]);
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame('native', $context['maintenance_mode']);
        $this->off()->handle($context);
        self::assertFalse(app()->isDownForMaintenance());
        self::assertCount(2, $this->shell->commands);
        self::assertContains('down', $this->shell->commands[0]);
        self::assertSame(['php', 'artisan', 'up'], $this->shell->commands[1]);
        self::assertSame($context['maintenance_ownership_id'], $this->shell->commandEnvironments[0]['UPDATER_MAINTENANCE_OWNER']);
        self::assertArrayNotHasKey('UPDATER_MAINTENANCE_OWNER', $this->shell->commandEnvironments[1]);
    }

    public function testNativeDriverMarksOwnershipAtomicallyBeforeDownListenersCanFail(): void
    {
        $identity = bin2hex(random_bytes(16));
        $runId = $this->store->createRun([]);
        UpdateMaintenanceState::recordPolicy([
            'run_id' => $runId,
            'maintenance_enabled' => true,
            'maintenance_owned' => true,
            'maintenance_mode' => 'native',
            'maintenance_ownership_id' => $identity,
        ], $this->store);
        $driver = app()->maintenanceMode();
        $this->app->instance(NativeMaintenanceContract::class, new OwnedNativeMaintenanceMode($driver, $identity));
        $this->app['events']->listen(MaintenanceModeEnabled::class, static function (): void {
            throw new \RuntimeException('Fixture listener failure after activation.');
        });
        self::assertSame(1, Artisan::call('down'));
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame($identity, app()->maintenanceMode()->data()[UpdateMaintenanceState::NATIVE_OWNER_KEY]);
        self::assertTrue($this->recovery()->recoverRun($runId, 'Down listener failed after activation.'));
        self::assertFalse(app()->isDownForMaintenance());
        self::assertFalse(UpdateMaintenanceState::forRun($this->store, $runId)['maintenance_owned']);
    }

    public function testProviderOnlyDecoratesTheNativeDriverForOwnedArtisanDown(): void
    {
        $provider = $this->app->getProvider(UpdaterServiceProvider::class);
        $registration = new \ReflectionMethod($provider, 'registerOwnedNativeMaintenance');
        $arguments = $_SERVER['argv'] ?? [];
        $previousOwner = getenv('UPDATER_MAINTENANCE_OWNER');
        $driver = app()->maintenanceMode();
        try {
            foreach ([
                ['artisan', 'up', str_repeat('a', 32)],
                ['artisan', 'down', 'invalid-identity'],
                ['phpunit', 'down', str_repeat('a', 32)],
            ] as [$entrypoint, $command, $owner]) {
                $_SERVER['argv'] = [$entrypoint, $command];
                putenv('UPDATER_MAINTENANCE_OWNER=' . $owner);
                $registration->invoke($provider);
                self::assertSame($driver, app()->maintenanceMode());
            }
            $_SERVER['argv'] = ['artisan', 'down'];
            putenv('UPDATER_MAINTENANCE_OWNER=' . str_repeat('b', 32));
            $registration->invoke($provider);
            self::assertInstanceOf(OwnedNativeMaintenanceMode::class, app()->maintenanceMode());
            app()->maintenanceMode()->activate(['time' => time()]);
            self::assertSame(str_repeat('b', 32), $driver->data()[UpdateMaintenanceState::NATIVE_OWNER_KEY]);
        } finally {
            $_SERVER['argv'] = $arguments;
            putenv($previousOwner === false ? 'UPDATER_MAINTENANCE_OWNER' : 'UPDATER_MAINTENANCE_OWNER=' . $previousOwner);
        }
    }

    public function testNativeMaintenanceAlreadyEnabledByClientIsPreservedOnSuccessAndRollback(): void
    {
        app()->maintenanceMode()->activate(['time' => time()]);
        $before = file_get_contents(storage_path('framework/down'));
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        self::assertFalse($context['maintenance_owned']);
        self::assertTrue($context['maintenance_preexisting']);
        $this->off()->handle($context);
        $this->on()->rollback($context);
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame($before, file_get_contents(storage_path('framework/down')));
        self::assertSame([], $this->shell->commands);
    }

    public function testSoftMaintenanceAlreadyPresentIsPreservedOnSuccessAndRollback(): void
    {
        Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, true);
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        $this->off()->handle($context);
        $this->on()->rollback($context);
        self::assertTrue(Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertSame([], $this->shell->commands);
    }

    public function testClientNativeMaintenanceEnteredDuringOurSoftRunIsNotRemoved(): void
    {
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        app()->maintenanceMode()->activate(['time' => time()]);
        $this->off()->handle($context);
        self::assertTrue(app()->isDownForMaintenance());
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertSame([], $this->shell->commands);
    }

    public function testSoftMaintenanceReactivatedByAdminDuringUpdateIsNotRemoved(): void
    {
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, true);
        $this->off()->handle($context);
        self::assertTrue(Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertFalse(UpdateMaintenanceState::forRun($this->store, $context['run_id'])['maintenance_owned']);
        self::assertSame(1, $this->lock->releases);
    }

    public function testNativeMaintenanceReactivatedByAdminDuringUpdateIsNotRemoved(): void
    {
        config(['updater.maintenance.soft_enabled' => false]);
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        app()->maintenanceMode()->activate(['time' => time(), 'message' => 'Manual maintenance']);
        $this->off()->handle($context);
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame('Manual maintenance', app()->maintenanceMode()->data()['message']);
        self::assertCount(1, $this->shell->commands);
        self::assertSame(1, $this->lock->releases);
    }

    public function testRecoveryAfterOffPreservesNewSoftMaintenanceAndReadsReleasedPolicy(): void
    {
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        $this->off()->handle($context);
        self::assertFalse(UpdateMaintenanceState::forRun($this->store, $context['run_id'])['maintenance_owned']);
        Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, true);
        self::assertTrue($this->recovery()->recoverRun($context['run_id'], 'Fatal after maintenance_off.'));
        self::assertTrue(Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertSame([], $this->shell->commands);
    }

    public function testRecoveryAfterOffPreservesNewNativeMaintenance(): void
    {
        config(['updater.maintenance.soft_enabled' => false]);
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        $this->off()->handle($context);
        self::assertFalse(UpdateMaintenanceState::forRun($this->store, $context['run_id'])['maintenance_owned']);
        app()->maintenanceMode()->activate(['time' => time(), 'message' => 'Manual maintenance after off']);
        self::assertTrue($this->recovery()->recoverRun($context['run_id'], 'Fatal after maintenance_off.'));
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame('Manual maintenance after off', app()->maintenanceMode()->data()['message']);
        self::assertCount(2, $this->shell->commands);
    }

    public function testCacheClearingRestoresOwnedSoftFlagOnSuccessAndRollback(): void
    {
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        $this->shell->flushCache = true;
        $cache = new CacheClearStep($this->shell);
        $cache->handle($context);
        self::assertSame($context['maintenance_ownership_id'], Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        $cache->rollback($context);
        self::assertSame($context['maintenance_ownership_id'], Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        $this->off()->handle($context);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
    }

    public function testFailingCacheClearPreservesPreexistingSoftMaintenance(): void
    {
        Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, true);
        $this->shell->flushCache = true;
        $this->shell->failAfterFlush = true;
        $context = [];
        try {
            (new CacheClearStep($this->shell))->handle($context);
            self::fail('The simulated optimize:clear failure must throw.');
        } catch (\Argws\LaravelUpdater\Exceptions\UpdaterException) {
            self::assertTrue(Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        }
        try {
            (new CacheClearStep($this->shell))->rollback($context);
            self::fail('The simulated rollback optimize:clear failure must throw.');
        } catch (\Argws\LaravelUpdater\Exceptions\UpdaterException) {
            self::assertTrue(Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        }
    }

    public function testCacheClearDoesNotEnableSoftMaintenanceWhenDisabled(): void
    {
        $this->shell->flushCache = true;
        $context = [];
        (new CacheClearStep($this->shell))->handle($context);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        (new CacheClearStep($this->shell))->rollback($context);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
    }

    public function testFailureReleasesLockAndOwnedMaintenance(): void
    {
        $context = ['run_id' => $this->store->createRun([])];
        $pipeline = new UpdatePipeline([
            new LockStep($this->lock, 30), $this->on(), $this->failureStep(), $this->off(),
        ], new NullLogger(), $this->store);
        try {
            $pipeline->run($context);
            self::fail('The failing update must throw.');
        } catch (\Argws\LaravelUpdater\Exceptions\PipelineException) {
            self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
            self::assertSame(1, $this->lock->releases);
            self::assertSame([], $this->shell->commands);
        }
    }

    public function testDisabledPipelineFailurePreservesNativeMaintenanceAndReleasesLock(): void
    {
        $this->manager->saveBranding(['enter_maintenance_on_update_start' => 0]);
        app()->maintenanceMode()->activate(['time' => time()]);
        $context = ['run_id' => $this->store->createRun([])];
        $pipeline = new UpdatePipeline([
            new LockStep($this->lock, 30), $this->on(), $this->failureStep(), $this->off(),
        ], new NullLogger(), $this->store);
        try {
            $pipeline->run($context);
            self::fail('The failing update must throw.');
        } catch (\Argws\LaravelUpdater\Exceptions\PipelineException) {
            self::assertTrue(app()->isDownForMaintenance());
            self::assertSame(1, $this->lock->releases);
            self::assertSame([], $this->shell->commands);
        }
    }

    public function testDryRunNeverActivatesMaintenanceEvenWhenEnabled(): void
    {
        $context = ['options' => ['dry_run' => true]];
        self::assertFalse($this->on()->shouldRun($context));
        $this->on()->handle($context);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertSame([], $this->shell->commands);
    }

    public function testRecoveryBeforeMaintenanceStepDoesNotRemoveClientNativeMaintenance(): void
    {
        app()->maintenanceMode()->activate(['time' => time()]);
        $runId = $this->store->createRun([]);
        self::assertTrue($this->recovery()->recoverRun($runId, 'Fixture recovery before maintenance.'));
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame([], $this->shell->commands);
    }

    public function testRecoveryCleansOnlyMaintenanceOwnedByInterruptedRun(): void
    {
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        self::assertTrue($this->recovery()->recoverRun($context['run_id'], 'Fixture recovery.'));
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertSame([], $this->shell->commands);
    }

    public function testFatalCleanupUsesOnlyOwnedNativeStateAndDoesNotStartAnotherProcess(): void
    {
        config(['updater.maintenance.soft_enabled' => false]);
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        self::assertTrue(app()->isDownForMaintenance());
        file_put_contents(storage_path('framework/maintenance.php'), '<?php echo "Fixture native maintenance";');
        $savedState = UpdateMaintenanceState::forRun($this->store, $context['run_id']);
        UpdateMaintenanceState::release($savedState, $this->shell, false);
        self::assertFalse(app()->isDownForMaintenance());
        self::assertFileDoesNotExist(storage_path('framework/maintenance.php'));
        self::assertCount(1, $this->shell->commands);
    }

    public function testRecoveryPreservesPreviouslyActiveClientMaintenance(): void
    {
        app()->maintenanceMode()->activate(['time' => time()]);
        $context = ['run_id' => $this->store->createRun([])];
        $this->on()->handle($context);
        self::assertTrue($this->recovery()->recoverRun($context['run_id'], 'Fixture preexisting recovery.'));
        self::assertTrue(app()->isDownForMaintenance());
        self::assertSame([], $this->shell->commands);
    }

    private function on(): MaintenanceOnStep { return new MaintenanceOnStep($this->shell, null, $this->manager, $this->store); }
    private function off(): MaintenanceOffStep { return new MaintenanceOffStep($this->shell, $this->lock, $this->store); }
    private function recovery(): UpdateRecoveryManager { return new UpdateRecoveryManager($this->store, new UpdaterLockTools(), $this->shell); }

    private function assertHostHttpStatusDuringPipeline(int $expected): void
    {
        $this->get('/customer-service')->assertOk()->assertSeeText('Customer application available');
        $context = ['run_id' => $this->store->createRun([])];
        $probe = function () use ($expected): void {
            $this->get('/customer-service')->assertStatus($expected);
        };
        $operation = new class($probe) implements PipelineStepInterface {
            public function __construct(private readonly \Closure $probe) {}
            public function name(): string { return 'fixture_http_request_during_update'; }
            public function shouldRun(array $context): bool { return true; }
            public function handle(array &$context): void { ($this->probe)(); }
            public function rollback(array &$context): void {}
        };
        (new UpdatePipeline([
            new LockStep($this->lock, 30), $this->on(), $operation, $this->off(),
        ], new NullLogger(), $this->store))->run($context);
        $this->get('/customer-service')->assertOk()->assertSeeText('Customer application available');
        self::assertSame(1, $this->lock->releases);
    }

    private function failureStep(): PipelineStepInterface
    {
        return new class implements PipelineStepInterface {
            public function name(): string { return 'fixture_failure'; }
            public function shouldRun(array $context): bool { return true; }
            public function handle(array &$context): void { throw new \RuntimeException('Fixture update failure.'); }
            public function rollback(array &$context): void {}
        };
    }
}

final class MaintenanceRecordingShell extends ShellRunner
{
    public array $commands = [];
    public array $commandEnvironments = [];
    public bool $flushCache = false;
    public bool $failAfterFlush = false;

    public function runOrFail(array $command, ?string $cwd = null, array $env = []): array
    {
        return $this->run($command, $cwd, $env);
    }

    public function run(array $command, ?string $cwd = null, array $env = []): array
    {
        $this->commands[] = $command;
        $this->commandEnvironments[] = $env;
        if ($this->flushCache && in_array('optimize:clear', $command, true)) {
            Cache::flush();
            if ($this->failAfterFlush) {
                throw new \Argws\LaravelUpdater\Exceptions\UpdaterException('Fixture cache clearing failure.');
            }
        }
        if (in_array('down', $command, true)) {
            $driver = app()->maintenanceMode();
            if (isset($env['UPDATER_MAINTENANCE_OWNER'])) {
                $driver = new OwnedNativeMaintenanceMode($driver, $env['UPDATER_MAINTENANCE_OWNER']);
            }
            $driver->activate(['time' => time()]);
        } elseif (in_array('up', $command, true)) {
            app()->maintenanceMode()->deactivate();
        }
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
    }
}

final class MaintenanceRecordingLock implements LockInterface
{
    public int $releases = 0;
    public function acquire(string $key, int $timeout): bool { return true; }
    public function release(): void { $this->releases++; }
    public function isAcquired(): bool { return true; }
}
