<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;

final class SpaPresentationTest extends TestCase
{
    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'reviewed/panel');
        $app['config']->set('updater.ui.auth.enabled', false);
        $app['config']->set('updater.ui.middleware', ['web']);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/reviewed/panel/fixture', fn () => view('laravel-updater::sections.sources', [
            'sources' => [[
                'id' => 77, 'name' => 'Production', 'repo_url' => 'https://git.example/repo.git',
                'branch' => 'main', 'auth_password' => 'fixture-stored-password',
                'token_encrypted' => 'fixture-stored-token', 'ssh_private_key_path' => '/secret/key',
            ]],
            'unapprovedExtra' => ['password' => 'unexpected-value'],
        ]))->name('updater.presentation.fixture');
        $router->middleware('web')->get('/reviewed/panel/denied', fn () => abort(403, 'Internal information must stay private'))->name('updater.presentation.denied');
        $router->middleware(['web', SpaRestrictedUserMiddleware::class, \Argws\LaravelUpdater\Http\Middleware\UpdaterAuthorizeMiddleware::class])
            ->get('/reviewed/panel/restricted', fn () => 'Must not execute')->name('updater.settings.restricted');
        $router->middleware('web')->get('/reviewed/panel/progress-fixture', fn () => response()->json([
            'message' => 'Failed using updater-config-fixture-secret',
            'run' => ['id' => 9, 'status' => 'running', 'options_json' => '{"password":"existing-secret"}', 'error_json' => '{"secret":"existing-secret"}'],
        ]))->name('updater.updates.progress.fixture');
        $router->middleware('web')->get('/host-page', fn () => 'Host remains independent')->name('host.page');
    }

    public function testEveryNamespaceViewUsesOnlyTheVueShellAndWhitelistedData(): void
    {
        $response = $this->get('/reviewed/panel/fixture');
        $response->assertOk()->assertSee('id="updater-app"', false)->assertSee('id="updater-bootstrap"', false);
        $response->assertDontSee('fixture-stored-password', false)->assertDontSee('fixture-stored-token', false)->assertDontSee('/secret/key', false)->assertDontSee('unexpected-value', false);
        $response->assertDontSee('<form', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        self::assertStringContainsString('"auth_password_configured":true', $response->getContent());
    }

    public function testSpaNavigationRunsTheSameRoutesAndReturnsPrefixAwareContext(): void
    {
        $this->getJson('/reviewed/panel/fixture', ['X-Updater-SPA' => '1'])
            ->assertOk()->assertJsonPath('page', 'sections.sources')
            ->assertJsonPath('context.basePath', '/reviewed/panel')
            ->assertJsonPath('data.sources.0.auth_password_configured', true)
            ->assertJsonMissingPath('data.sources.0.auth_password')
            ->assertJsonMissingPath('data.sources.0.token_encrypted')
            ->assertJsonMissingPath('data.unapprovedExtra');
    }

    public function testCreatedApiTokenIsConsumedOnlyOnce(): void
    {
        $this->withSession(['token_plain' => 'one-time-created-value'])
            ->getJson('/reviewed/panel/fixture', ['X-Updater-SPA' => '1'])
            ->assertJsonPath('context.flash.token_plain', 'one-time-created-value');
        $this->getJson('/reviewed/panel/fixture', ['X-Updater-SPA' => '1'])
            ->assertJsonMissingPath('context.flash.token_plain');
    }

    public function testDeniedScreenIsVueAndDoesNotDiscloseTheExceptionMessage(): void
    {
        $this->getJson('/reviewed/panel/denied', ['X-Updater-SPA' => '1'])
            ->assertForbidden()->assertJsonPath('page', 'error')->assertJsonPath('data.status', 403)
            ->assertDontSee('Internal information', false);
        $this->get('/host-page')->assertOk()->assertSeeText('Host remains independent')->assertDontSee('updater-app', false);
    }

    public function testCompiledAssetsAreAvailableWithoutPublishing(): void
    {
        $asset = app(\Argws\LaravelUpdater\Support\SpaAssets::class)->entry();
        self::assertNotEmpty($asset['js'], 'Production assets must be included in the Composer package.');
        self::assertNotEmpty($asset['css'], 'The shared Vite stylesheet must be loaded even with cssCodeSplit=false.');
        $css = $this->get(parse_url($asset['css'][0], PHP_URL_PATH))->assertOk()->assertHeader('Content-Type');
        self::assertStringStartsWith('text/css', strtolower($css->headers->get('Content-Type')));
        $this->get('/reviewed/panel/fixture')->assertSee('rel="stylesheet"', false);
        $this->get(parse_url($asset['js'], PHP_URL_PATH))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testPublishedLegacyOverrideCannotExecuteBladeOrResolveRemovedRoutes(): void
    {
        $directory = sys_get_temp_dir() . '/updater-legacy-' . bin2hex(random_bytes(6));
        mkdir($directory . '/sections', 0775, true);
        file_put_contents($directory . '/sections/sources.blade.php', "LEGACY VISUAL {{ route('updater.profiles.save') }}");
        $this->app['view']->getFinder()->prependNamespace('laravel-updater', $directory);
        try {
            $this->get('/reviewed/panel/fixture')->assertOk()->assertSee('id="updater-app"', false)->assertDontSee('LEGACY VISUAL', false);
        } finally {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($directory);
        }
    }

    public function testMaintenanceRemainsA503VuePageEvenOutsideUpdaterRoutes(): void
    {
        \Illuminate\Support\Facades\Cache::put(\Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware::CACHE_KEY, true);
        $this->get('/host-page')->assertStatus(503)->assertSee('id="updater-app"', false)->assertSee('"page":"maintenance"', false);
    }

    public function testOldCachedRouteNamesUseCompiledAssetsThroughTheirLegacyEndpoints(): void
    {
        $routes = new \Illuminate\Routing\RouteCollection();
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getName() !== 'updater.spa.asset') { $routes->add($route); }
        }
        $routes->refreshNameLookups();
        $this->app['router']->setRoutes($routes);
        $assets = app(\Argws\LaravelUpdater\Support\SpaAssets::class)->entry();
        self::assertStringContainsString('spa=1', $assets['js'] ?? '');
        $url = parse_url($assets['js'], PHP_URL_PATH) . '?' . parse_url($assets['js'], PHP_URL_QUERY);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/javascript');
    }


    public function testBackendAuthorizationStillRejectsAnAdminWithRestrictedPermissions(): void
    {
        config(['updater.ui.auth.enabled' => true]);
        $this->getJson('/reviewed/panel/restricted', ['X-Updater-SPA' => '1'])
            ->assertForbidden()->assertJsonPath('page', 'error')
            ->assertJsonPath('context.user.permissions.0', 'logs.view')
            ->assertDontSee('Must not execute', false);
    }

    public function testOperationalJsonNeverIncludesStoredRunOptionsOrErrors(): void
    {
        config(['updater.sync_token' => 'updater-config-fixture-secret']);
        $this->getJson('/reviewed/panel/progress-fixture')
            ->assertOk()->assertJsonPath('run.status', 'running')
            ->assertJsonMissingPath('run.options_json')->assertJsonMissingPath('run.error_json')
            ->assertDontSee('existing-secret', false)->assertDontSee('updater-config-fixture-secret', false);
    }


    public function testHostAuthenticationSectionsNeverRedirectToMissingCrudRoutesForEitherPrefix(): void
    {
        foreach (['reviewed/panel', '_updater'] as $prefix) {
            if ($prefix === '_updater') {
                config(['updater.ui.prefix' => $prefix]);
                $this->app['router']->setRoutes(new \Illuminate\Routing\RouteCollection());
                require dirname(__DIR__, 2) . '/routes/updater.php';
                $this->app['router']->getRoutes()->refreshNameLookups();
            }
            foreach (['profiles', 'settings', 'seeds', 'admin-users'] as $section) {
                $this->getJson('/' . $prefix . '/' . $section, ['X-Updater-SPA' => '1'])
                    ->assertForbidden()->assertJsonPath('page', 'error')->assertJsonPath('data.status', 403);
            }
            self::assertFalse(\Illuminate\Support\Facades\Route::has('updater.profiles.store'));
            self::assertFalse(\Illuminate\Support\Facades\Route::has('updater.settings.index'));
            self::assertSame($prefix . '/trigger-update', \Illuminate\Support\Facades\Route::getRoutes()->getByName('updater.trigger.update')->uri());
            self::assertSame($prefix . '/migrations', \Illuminate\Support\Facades\Route::getRoutes()->getByName('updater.migrations.index')->uri());
        }
    }

    public function testHostLegacyTriggerStillDispatchesAnExplicitSimulation(): void
    {
        $state = app(\Argws\LaravelUpdater\Support\StateStore::class);
        $kernel = $this->createMock(\Argws\LaravelUpdater\Kernel\UpdaterKernel::class);
        $kernel->method('stateStore')->willReturn($state);
        $this->app->instance(\Argws\LaravelUpdater\Kernel\UpdaterKernel::class, $kernel);
        $dispatcher = $this->createMock(\Argws\LaravelUpdater\Support\TriggerDispatcher::class);
        $dispatcher->expects(self::once())->method('triggerUpdate')->with(self::callback(static fn (array $options): bool => $options['dry_run'] === true));
        $this->app->instance(\Argws\LaravelUpdater\Support\TriggerDispatcher::class, $dispatcher);
        $this->post('/reviewed/panel/trigger-update', ['action' => 'simulate', 'dry_run_before' => '1'])
            ->assertRedirect()->assertSessionHas('status');
    }

}


final class SpaRestrictedUserMiddleware
{
    public function handle($request, \Closure $next)
    {
        $request->attributes->set('updater_user', ['id' => 8, 'name' => 'Limited administrator', 'is_admin' => 1, 'permissions' => ['logs.view']]);
        return $next($request);
    }
}
