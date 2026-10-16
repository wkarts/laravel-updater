<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateMaintenanceState;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOnStep;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;

final class MaintenancePolicySettingsTest extends TestCase
{
    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'policy/manager');
        $app['config']->set('updater.ui.auth.enabled', true);
        $app['config']->set('updater.ui.auth.auto_provision_admin', false);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('p', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $auth = app(AuthStore::class);
        $admin = $auth->createUser('policy-admin@example.test', 'fixture-admin-password', true, 'Policy Admin');
        $this->withCookie('updater_session', $auth->createSession($admin, '127.0.0.1', 'Policy Test', 60));
    }

    public function testNewInstallationUsesTheRealDisabledPackageDefaultWithoutAnEnvironmentParameter(): void
    {
        // No defineEnvironment override is used for this policy: this exercises
        // config/updater.php loaded by the package provider on a new install.
        $package = $this->packageConfigFromEnvironment(null);
        self::assertFalse($package['maintenance']['enter_on_update_start']);
        self::assertFalse(config('updater.maintenance.enter_on_update_start'));
        $store = app(ManagerStore::class);
        self::assertNull($store->branding());
        self::assertFalse(UpdateMaintenanceState::enabled($store));
        self::assertSame(0, $store->resolvedBranding()['enter_maintenance_on_update_start']);
        self::assertFalse($store->runtimeSettings()['maintenance']['enter_on_update_start']);
        self::assertFalse((new MaintenanceOnStep(app(ShellRunner::class), null, $store))->shouldRun([]));
        $this->getJson('/policy/manager/settings?tab=maintenance', ['X-Updater-SPA' => '1'])
            ->assertOk()->assertJsonPath('data.branding.enter_maintenance_on_update_start', 0);
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
    }

    public function testLegacyEnvironmentStillWorksAndSavedManagerChoiceTakesPrecedence(): void
    {
        $store = app(ManagerStore::class);
        foreach (['true' => true, 'false' => false, '1' => true, '0' => false] as $raw => $expected) {
            $this->withLegacyEnvironment((string) $raw, function () use ($store, $expected): void {
                $package = require __DIR__ . '/../../config/updater.php';
                config(['updater.maintenance.enter_on_update_start' => $package['maintenance']['enter_on_update_start']]);
                self::assertSame($expected, UpdateMaintenanceState::enabled($store));
                self::assertSame((int) $expected, $store->resolvedBranding()['enter_maintenance_on_update_start']);
                self::assertSame($expected, $store->runtimeSettings()['maintenance']['enter_on_update_start']);
                foreach (['0', '1'] as $saved) {
                    $this->post('/policy/manager/settings/maintenance', ['enter_maintenance_on_update_start' => $saved])
                        ->assertRedirect('/policy/manager/settings?tab=maintenance');
                    self::assertSame($saved === '1', UpdateMaintenanceState::enabled($store));
                    self::assertSame((int) $saved, $store->resolvedBranding()['enter_maintenance_on_update_start']);
                    self::assertSame($saved === '1', $store->runtimeSettings()['maintenance']['enter_on_update_start']);
                    $this->getJson('/policy/manager/settings?tab=maintenance', ['X-Updater-SPA' => '1'])
                        ->assertOk()->assertJsonPath('data.branding.enter_maintenance_on_update_start', (int) $saved);
                }
                // Return to a new installation before checking the next env fallback.
                app(StateStore::class)->pdo()->exec('DELETE FROM updater_branding');
            });
        }
    }

    public function testPreviouslySavedEnabledPolicySurvivesTheNewDisabledDefault(): void
    {
        $store = app(ManagerStore::class);
        $store->saveBranding(['enter_maintenance_on_update_start' => 1]);
        self::assertFalse(config('updater.maintenance.enter_on_update_start'));
        self::assertTrue(UpdateMaintenanceState::enabled($store));
        self::assertSame(1, $store->resolvedBranding()['enter_maintenance_on_update_start']);
        self::assertTrue($store->runtimeSettings()['maintenance']['enter_on_update_start']);
        $this->getJson('/policy/manager/settings?tab=maintenance', ['X-Updater-SPA' => '1'])
            ->assertOk()->assertJsonPath('data.branding.enter_maintenance_on_update_start', 1);
    }

    public function testOldPublishedTrueDefaultWithoutAnExplicitEnvironmentValueDoesNotEnableMaintenance(): void
    {
        $this->withLegacyEnvironment(null, function (): void {
            $this->withEnvironmentFileContents(null, function (): void {
                $fixture = tempnam(sys_get_temp_dir(), 'updater-legacy-config-');
                try {
                    file_put_contents($fixture, '<?php return ["maintenance" => ["enter_on_update_start" => (bool) env("UPDATER_MAINTENANCE_ENTER_ON_UPDATE_START", true)]];');
                    $published = require $fixture;
                    self::assertTrue($published['maintenance']['enter_on_update_start']);
                    config(['updater.maintenance.enter_on_update_start' => $published['maintenance']['enter_on_update_start']]);
                    $store = app(ManagerStore::class);
                    self::assertFalse(UpdateMaintenanceState::enabled($store));
                    self::assertSame(0, $store->resolvedBranding()['enter_maintenance_on_update_start']);
                    self::assertFalse($store->runtimeSettings()['maintenance']['enter_on_update_start']);
                } finally {
                    @unlink($fixture);
                }
            });
        });
    }

    public function testCachedConfigUsesOnlyTheExplicitLegacyFlagAndTheSavedManagerChoiceWins(): void
    {
        $this->withLegacyEnvironment(null, function (): void {
            $key = 'UPDATER_MAINTENANCE_ENTER_ON_UPDATE_START';
            foreach (['true' => true, 'false' => false, '(true)' => true, '(false)' => false, '"true" # legacy setting' => true] as $raw => $expected) {
                $this->withEnvironmentFileContents("UNRELATED_VALUE=must-not-be-loaded\n{$key}={$raw}\n", function () use ($expected): void {
                    // Simulate a stale cached/published configuration opposite to
                    // the explicitly configured value in the existing env file.
                    config(['updater.maintenance.enter_on_update_start' => !$expected]);
                    $store = app(ManagerStore::class);
                    self::assertSame($expected, UpdateMaintenanceState::enabled($store));
                    self::assertNull(env('UNRELATED_VALUE'));
                    $store->saveBranding(['enter_maintenance_on_update_start' => (int) !$expected]);
                    self::assertSame(!$expected, UpdateMaintenanceState::enabled($store));
                    app(StateStore::class)->pdo()->exec('DELETE FROM updater_branding');
                });
            }
        });
    }

    public function testProcessEnvironmentHasPriorityOverTheLegacyFileAndUiOverridesBoth(): void
    {
        $this->withLegacyEnvironment('true', function (): void {
            $this->withEnvironmentFileContents("UPDATER_MAINTENANCE_ENTER_ON_UPDATE_START=false\n", function (): void {
                $store = app(ManagerStore::class);
                self::assertTrue(UpdateMaintenanceState::enabled($store));
                $this->post('/policy/manager/settings/maintenance', ['enter_maintenance_on_update_start' => '0'])
                    ->assertRedirect('/policy/manager/settings?tab=maintenance');
                self::assertFalse(UpdateMaintenanceState::enabled($store));
                self::assertSame(0, $store->resolvedBranding()['enter_maintenance_on_update_start']);
            });
        });
    }

    public function testPolicyIsNotDuplicatedOrWritableInTheGenericConfigurationOrEnvironmentForms(): void
    {
        $store = app(ManagerStore::class);
        $settings = $this->getJson('/policy/manager/settings?tab=runtime', ['X-Updater-SPA' => '1'])->assertOk();
        $definitions = array_column($settings->json('data.updaterConfigFields'), 'config');
        self::assertNotContains('updater.maintenance.enter_on_update_start', $definitions);
        self::assertContains('updater.enabled', $definitions);
        $environment = $this->getJson('/policy/manager/settings/env', ['X-Updater-SPA' => '1'])->assertOk();
        $definitions = array_column($environment->json('data.envFields'), 'config');
        self::assertNotContains('updater.maintenance.enter_on_update_start', $definitions);
        self::assertContains('updater.enabled', $definitions);
        $this->post('/policy/manager/settings/config', [
            'updater_maintenance_enter_on_update_start' => '1', 'updater_enabled' => '1',
        ])->assertRedirect();
        $overrides = $store->getRuntimeOption('updater_config_overrides', []);
        self::assertArrayNotHasKey('updater.maintenance.enter_on_update_start', $overrides);
        self::assertTrue($overrides['updater.enabled']);
        self::assertFalse(UpdateMaintenanceState::enabled($store));
        $this->withEnvironmentFileContents("APP_NAME=Fixture\n", function (): void {
            $basePath = $this->app->basePath();
            try {
                $this->app->setBasePath($this->app->environmentPath());
                $this->post('/policy/manager/settings/env', [
                    'updater_maintenance_enter_on_update_start' => '1', 'updater_enabled' => '1',
                ])->assertRedirect();
                $contents = file_get_contents(base_path('.env'));
                self::assertStringNotContainsString('UPDATER_MAINTENANCE_ENTER_ON_UPDATE_START=', $contents);
                self::assertStringContainsString('UPDATER_ENABLED=true', $contents);
            } finally {
                $this->app->setBasePath($basePath);
            }
        });
    }

    public function testNewEnvironmentProfilesDoNotGenerateTheLegacyMaintenanceParameter(): void
    {
        foreach (['default', 'homolog', 'production'] as $profile) {
            $contents = file_get_contents(__DIR__ . '/../../stubs/env/updater.' . $profile . '.env.example');
            self::assertIsString($contents);
            self::assertStringNotContainsString('UPDATER_MAINTENANCE_ENTER_ON_UPDATE_START=', $contents);
        }
    }

    public function testDedicatedFormSavesDisabledAndEnabledAndReloadsTheSamePolicy(): void
    {
        $store = app(ManagerStore::class);
        $store->saveBranding(['app_name' => 'Customer Identity', 'logo_path' => 'updater/branding/customer.png',
            'favicon_path' => 'updater/branding/customer.ico', 'maintenance_title' => 'Customer maintenance']);
        foreach (['0', '1', '0'] as $value) {
            $this->post('/policy/manager/settings/maintenance', ['enter_maintenance_on_update_start' => $value])
                ->assertRedirect('/policy/manager/settings?tab=maintenance');
            self::assertSame((int) $value, $store->branding()['enter_maintenance_on_update_start']);
            self::assertSame($value === '1', UpdateMaintenanceState::enabled($store));
            self::assertSame('Customer Identity', $store->branding()['app_name']);
            self::assertSame('updater/branding/customer.png', $store->branding()['logo_path']);
            self::assertSame('updater/branding/customer.ico', $store->branding()['favicon_path']);
            self::assertSame('Customer maintenance', $store->branding()['maintenance_title']);
            self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
            self::assertFalse(app()->isDownForMaintenance());
            $response = $this->getJson('/policy/manager/settings?tab=maintenance', ['X-Updater-SPA' => '1'])
                ->assertOk()->assertJsonPath('data.branding.enter_maintenance_on_update_start', (int) $value);
            self::assertSame('http://localhost/policy/manager/settings/maintenance', $response->json('context.routes')['updater.settings.maintenance.save']);
        }
        self::assertFalse(Cache::has(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertFalse(app()->isDownForMaintenance());
    }

    public function testSavingBrandingWithoutTheOldCheckboxPreservesTheDisabledPolicy(): void
    {
        app(ManagerStore::class)->saveBranding(['enter_maintenance_on_update_start' => 0]);
        $this->post('/policy/manager/settings/branding', ['app_name' => 'Updated identity'])
            ->assertRedirect();
        self::assertFalse(UpdateMaintenanceState::enabled(app(ManagerStore::class)));
        self::assertSame('Updated identity', app(ManagerStore::class)->branding()['app_name']);
    }

    public function testResettingVisualIdentityKeepsTheSeparatelyConfiguredMaintenancePolicy(): void
    {
        config(['updater.app.name' => 'Environment name', 'updater.app.primary_color' => '#123456']);
        $store = app(ManagerStore::class);
        $store->saveBranding(['app_name' => 'Custom name', 'primary_color' => '#654321',
            'logo_path' => 'updater/branding/custom.png', 'enter_maintenance_on_update_start' => 0]);
        $this->post('/policy/manager/settings/branding/reset')->assertRedirect();
        self::assertFalse(UpdateMaintenanceState::enabled($store));
        self::assertSame('Environment name', $store->resolvedBranding()['app_name']);
        self::assertSame('#123456', $store->resolvedBranding()['primary_color']);
        self::assertNull($store->branding()['logo_path']);
    }

    public function testOmittedPolicyDoesNotOverrideTheEnvironmentDefault(): void
    {
        config(['updater.maintenance.enter_on_update_start' => false]);
        $this->post('/policy/manager/settings/branding', ['app_name' => 'Identity only'])->assertRedirect();
        self::assertNull(app(ManagerStore::class)->branding()['enter_maintenance_on_update_start']);
        self::assertFalse(UpdateMaintenanceState::enabled(app(ManagerStore::class)));
    }

    public function testSavingPolicyDoesNotChangeManualMaintenance(): void
    {
        Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, true);
        $this->post('/policy/manager/settings/maintenance', ['enter_maintenance_on_update_start' => '0'])->assertRedirect();
        self::assertTrue(Cache::get(SoftMaintenanceMiddleware::CACHE_KEY));
        self::assertFalse(UpdateMaintenanceState::enabled(app(ManagerStore::class)));
        Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);
    }

    public function testSettingsPermissionIsRequiredAndInvalidValuesAreRejected(): void
    {
        $store = app(ManagerStore::class);
        $store->saveBranding(['enter_maintenance_on_update_start' => 0]);
        $this->postJson('/policy/manager/settings/maintenance', ['enter_maintenance_on_update_start' => 'unexpected'])
            ->assertUnprocessable()->assertJsonValidationErrors('enter_maintenance_on_update_start');
        $this->postJson('/policy/manager/settings/maintenance', [])->assertUnprocessable();
        $auth = app(AuthStore::class);
        $reader = $auth->createUser('policy-reader@example.test', 'fixture-reader-password', false, 'Reader');
        $this->withCookie('updater_session', $auth->createSession($reader, '127.0.0.1', 'Policy Test', 60));
        $this->post('/policy/manager/settings/maintenance', ['enter_maintenance_on_update_start' => '1'])->assertForbidden();
        self::assertFalse(UpdateMaintenanceState::enabled($store));
    }

    private function packageConfigFromEnvironment(?string $value): array
    {
        return $this->withLegacyEnvironment($value, static fn () => require __DIR__ . '/../../config/updater.php');
    }

    private function withLegacyEnvironment(?string $value, \Closure $assertions): mixed
    {
        $key = 'UPDATER_MAINTENANCE_ENTER_ON_UPDATE_START';
        $environment = $_ENV;
        $server = $_SERVER;
        $previous = getenv($key);
        try {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key . '=' . $value);
            }
            return $assertions();
        } finally {
            $_ENV = $environment;
            $_SERVER = $server;
            putenv($previous === false ? $key : $key . '=' . $previous);
        }
    }

    private function withEnvironmentFileContents(?string $contents, \Closure $assertions): mixed
    {
        $directory = sys_get_temp_dir() . '/updater-maintenance-env-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $previousPath = $this->app->environmentPath();
        $previousFile = $this->app->environmentFile();
        try {
            if ($contents !== null) {
                file_put_contents($directory . '/.env', $contents);
            }
            $this->app->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');
            return $assertions();
        } finally {
            $this->app->useEnvironmentPath($previousPath)->loadEnvironmentFrom($previousFile);
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($directory);
        }
    }
}
