<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Support\SpaAssets;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

final class NativeMaintenancePresentationTest extends TestCase
{
    private string $maintenanceStorage;
    private string $originalStorage;

    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'safe/updater');
        $app['config']->set('updater.ui.auth.enabled', true);
        $app['config']->set('updater.ui.auth.auto_provision_admin', false);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('updater.maintenance.soft_enabled', false);
        $app['config']->set('app.maintenance.driver', 'file');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('n', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = $this->app->storagePath();
        $this->maintenanceStorage = sys_get_temp_dir() . '/updater-native-maintenance-' . bin2hex(random_bytes(7));
        mkdir($this->maintenanceStorage . '/framework', 0775, true);
        $this->app->useStoragePath($this->maintenanceStorage);
    }

    protected function tearDown(): void
    {
        if (isset($this->maintenanceStorage)) {
            $this->app->maintenanceMode()->deactivate();
            $this->app->useStoragePath($this->originalStorage);
            (new Filesystem())->deleteDirectory($this->maintenanceStorage);
        }
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/outside-updater', fn () => 'Host endpoint must remain blocked.');
        $router->get('/safe/updater-other', fn () => 'Similar prefix must remain blocked.');
    }

    public function testNativePrerendered503LoadsVueAssetsWhileAdminStillRequiresAuthentication(): void
    {
        self::assertSame(0, Artisan::call('down', ['--render' => 'laravel-updater::maintenance']));
        $data = $this->app->maintenanceMode()->data();
        self::assertContains('safe/updater', $data['except']);
        self::assertContains('safe/updater/*', $data['except']);
        self::assertStringContainsString('id="updater-app"', $data['template']);
        self::assertStringContainsString('rel="stylesheet"', $data['template']);
        $this->get('/outside-updater')->assertStatus(503)->assertSee('id="updater-app"', false)->assertDontSee('Host endpoint must remain blocked.', false);
        $this->get('/safe/updater-other')->assertStatus(503)->assertDontSee('Similar prefix must remain blocked.', false);
        $this->get('/safe/updater/login')->assertOk()->assertSee('id="updater-app"', false);
        $this->get('/safe/updater/settings')->assertRedirect('/safe/updater/login');
        $assets = app(SpaAssets::class)->entry();
        self::assertNotEmpty($assets['css']);
        foreach ([$assets['js'], ...$assets['css']] as $url) {
            $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
            self::assertStringContainsString(SpaAssets::QUERY_PARAMETER . '=', $path);
            $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            // Exercise Laravel's early maintenance.php before Composer/provider boot too.
            $process = new Process([PHP_BINARY, '-r', '$_SERVER["REQUEST_URI"]=$argv[1]; require $argv[2]; echo "PASSED_EARLY_GATE";', $path, $this->maintenanceStorage . '/framework/maintenance.php']);
            $process->mustRun();
            self::assertSame('PASSED_EARLY_GATE', $process->getOutput());
        }
        $process = new Process([PHP_BINARY, '-r', '$_SERVER["REQUEST_URI"]="/outside-updater"; require $argv[1]; echo "UNEXPECTED_BYPASS";', $this->maintenanceStorage . '/framework/maintenance.php']);
        $process->mustRun();
        self::assertStringContainsString('id="updater-app"', $process->getOutput());
        self::assertStringNotContainsString('UNEXPECTED_BYPASS', $process->getOutput());
        self::assertSame(0, Artisan::call('up'));
        $this->get('/outside-updater')->assertOk()->assertSeeText('Host endpoint must remain blocked.');
    }

    public function testNativeExclusionsPreserveHostMiddlewareAndItsExistingRules(): void
    {
        $middleware = $this->app->make(SpaHostNativeMaintenanceMiddleware::class);
        self::assertInstanceOf(SpaHostNativeMaintenanceMiddleware::class, $middleware);
        self::assertSame(['existing-public-path', 'safe/updater', 'safe/updater/*'], $middleware->getExcludedPaths());
        self::assertNotContains('*', $middleware->getExcludedPaths());
    }
}

final class SpaHostNativeMaintenanceMiddleware extends PreventRequestsDuringMaintenance
{
    protected $except = ['existing-public-path'];
}
