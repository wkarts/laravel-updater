<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Argws\LaravelUpdater\Support\SpaPresenter;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\TestCase;

final class MaintenanceAvailabilityTest extends TestCase
{
    private string $temporaryStorage;

    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'control/updater');
        $app['config']->set('updater.ui.auth.enabled', true);
        $app['config']->set('updater.ui.auth.auto_provision_admin', false);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('app.maintenance.driver', 'file');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('v', 32)));
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/', fn () => redirect('/grafico'));
        $router->middleware('web')->get('/grafico', fn () => response('Actual host application', 200));
        $router->middleware('web')->get('/health', fn () => response('Bypassed health endpoint', 200));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryStorage = sys_get_temp_dir() . '/updater-availability-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryStorage . '/framework', 0775, true);
        $this->app->useStoragePath($this->temporaryStorage);
    }

    protected function tearDown(): void
    {
        Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);
        $this->app->maintenanceMode()->deactivate();
        URL::forceRootUrl(null);
        (new Filesystem())->deleteDirectory($this->temporaryStorage);
        parent::tearDown();
    }

    public function testSoftMaintenanceHasPublicRootContextAndRealAvailabilityDiffersFromBypassedHealth(): void
    {
        Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, true);
        $this->get('/grafico')->assertStatus(503)->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
        $this->get('/health')->assertOk();
        $this->get('/')->assertStatus(503);
        $payload = $this->bootstrap($this->get('/grafico')->getContent());
        self::assertSame('maintenance', $payload['page']);
        self::assertSame('/', $payload['context']['applicationRootPath']);
        self::assertSame('/control/updater', $payload['context']['basePath']);
        Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);
        $this->get('/')->assertRedirect('/grafico');
        $this->get('/grafico')->assertOk()->assertSeeText('Actual host application');
    }

    public function testRootContextPreservesSubdirectoriesAndCompositePrefixesForConsoleRender(): void
    {
        URL::forceRootUrl('https://app.example/erp');
        $payload = app(SpaPresenter::class)->present('maintenance', [], Request::create('https://app.example/'));
        self::assertSame('/erp/control/updater', $payload['context']['basePath']);
        self::assertSame('/erp/', $payload['context']['applicationRootPath']);
    }

    public function testNativePrerenderIncludesTheApplicationRootEvenWithSyntheticCliRequest(): void
    {
        URL::forceRootUrl('https://app.example/erp');
        self::assertSame(0, Artisan::call('down', ['--render' => 'laravel-updater::maintenance']));
        $payload = $this->bootstrap($this->app->maintenanceMode()->data()['template']);
        self::assertSame('maintenance', $payload['page']);
        self::assertSame('/erp/', $payload['context']['applicationRootPath']);
        self::assertSame('/erp/control/updater', $payload['context']['basePath']);
    }

    private function bootstrap(string $html): array
    {
        self::assertSame(1, preg_match('/<script type="application\/json" id="updater-bootstrap">(.*?)<\/script>/s', $html, $match));
        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
