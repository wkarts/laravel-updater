<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\UpdateMaintenanceState;
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
        $app['config']->set('updater.maintenance.enter_on_update_start', true);
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
}
