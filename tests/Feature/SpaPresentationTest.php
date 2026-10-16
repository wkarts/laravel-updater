<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Support\SpaAssets;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    public function testCompiledAssetsAreAvailableWithoutPublishingAndWithoutStaticExtensionsInThePath(): void
    {
        $assets = app(SpaAssets::class)->entry();
        self::assertNotEmpty($assets['js'], 'Production assets must be included in the Composer package.');
        self::assertNotEmpty($assets['css'], 'The shared Vite stylesheet must be loaded even with cssCodeSplit=false.');
        foreach ([$assets['js'], ...$assets['css']] as $url) {
            $this->assertPublicCompiledAsset($url);
        }
        $this->get('/reviewed/panel/fixture')->assertOk()->assertSee('rel="stylesheet"', false);
    }

    public function testExtensionlessBundlesRemainAvailableWithUiAndHealthDisabled(): void
    {
        config(['updater.ui.enabled' => false, 'updater.healthcheck.enabled' => false]);
        $this->app['router']->setRoutes(new RouteCollection());
        require dirname(__DIR__, 2) . '/routes/updater.php';
        require dirname(__DIR__, 2) . '/routes/spa.php';
        $this->app['router']->getRoutes()->refreshNameLookups();
        self::assertFalse(\Illuminate\Support\Facades\Route::has('updater.index'));
        self::assertFalse(\Illuminate\Support\Facades\Route::has('updater.health'));
        $assets = app(SpaAssets::class)->entry();
        foreach ([$assets['js'], ...$assets['css']] as $url) {
            $this->assertPublicCompiledAsset($url);
        }
    }

    public function testHashedAssetPathsAndLegacySpaQueryAliasesRemainCompatible(): void
    {
        $assets = app(SpaAssets::class)->entry();
        foreach ([$assets['js'], ...$assets['css']] as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $file = $query[SpaAssets::QUERY_PARAMETER];
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            foreach ([
                '/reviewed/panel/assets/spa/' . $file,
                '/reviewed/panel/assets/updater.' . $extension . '?spa=1',
            ] as $alias) {
                $response = $this->get($alias)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
                self::assertSame(
                    hash_file('sha256', SpaAssets::directory() . '/' . $file),
                    hash('sha256', $this->binaryBody($response))
                );
            }
        }
    }

    public function testReservedAssetQueryRejectsInvalidFilesWithoutExposingHtmlOrPackageMetadata(): void
    {
        $endpoint = parse_url(app(SpaAssets::class)->entry()['js'], PHP_URL_PATH);
        foreach (['', '../assets/updater.css', '../../composer.json', '.vite/manifest.json', 'assets/missing.js', 'https://example.com/code.js', "assets/invalid\0.js"] as $file) {
            $this->get($endpoint . '?' . http_build_query([SpaAssets::QUERY_PARAMETER => $file]))
                ->assertNotFound()->assertDontSee('id="updater-bootstrap"', false);
        }
        $this->get($endpoint . '?' . http_build_query([SpaAssets::QUERY_PARAMETER => ['assets/example.js']]))
            ->assertNotFound();
        $this->get($endpoint)->assertNotFound();
    }

    public function testReservedAssetQueryCannotInterceptAnApiOrHostRoute(): void
    {
        $assets = app(SpaAssets::class)->entry();
        $query = parse_url($assets['js'], PHP_URL_QUERY);
        $this->get('/host-page?' . $query)->assertOk()->assertSeeText('Host remains independent')
            ->assertDontSee('updater-app', false);
        $this->getJson('/reviewed/panel/api/status?' . $query)->assertUnauthorized()
            ->assertJsonPath('code', 'unauthorized');
        $this->post('/reviewed/panel?' . $query)->assertStatus(405);
    }

    public function testLegacyAssetsStillServeThePackagedBytesWithoutPublishing(): void
    {
        foreach (['js' => 'application/javascript', 'css' => 'text/css'] as $extension => $mime) {
            $response = $this->get('/reviewed/panel/assets/updater.' . $extension)
                ->assertOk();
            self::assertStringStartsWith($mime, (string) $response->headers->get('Content-Type'));
            self::assertSame(
                hash_file('sha256', dirname(__DIR__, 2) . '/resources/assets/updater.' . $extension),
                hash('sha256', $this->binaryBody($response))
            );
        }
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

    public function testOldCachedRouteTablesServeBothBundlesBeforeOwnOrHostAuthentication(): void
    {
        foreach ([true, false] as $ownAuthentication) {
            config([
                'updater.ui.auth.enabled' => $ownAuthentication,
                'updater.ui.auth.auto_provision_admin' => false,
                'updater.ui.middleware' => ['web', SpaHostRejectUnauthenticatedMiddleware::class],
            ]);
            $this->reloadLegacyRouteTable();
            $assets = app(SpaAssets::class)->entry();
            foreach ([$assets['js'], ...$assets['css']] as $url) {
                self::assertSame('/reviewed/panel', rtrim(parse_url($url, PHP_URL_PATH), '/'));
                $this->assertPublicCompiledAsset($url);
            }
            // The reserved public read must not make the normal protected page public.
            $normalPage = $this->get('/reviewed/panel');
            if ($ownAuthentication) {
                $normalPage->assertRedirect('/reviewed/panel/login');
                $query = parse_url($assets['js'], PHP_URL_QUERY);
                $this->get('/reviewed/panel/settings?' . $query)->assertRedirect('/reviewed/panel/login');
            } else {
                $normalPage->assertForbidden();
            }
        }
    }

    public function testOldCachedRouteFallbackAlsoUsesExistingLoginOrHealthRoutes(): void
    {
        config(['updater.ui.auth.enabled' => true, 'updater.ui.auth.auto_provision_admin' => false]);
        $this->reloadLegacyRouteTable();
        foreach ([['updater.index'], ['updater.index', 'updater.login']] as $removedRoutes) {
            $this->removeRoutes($removedRoutes);
            $assets = app(SpaAssets::class)->entry();
            $expected = count($removedRoutes) === 1 ? '/reviewed/panel/login' : '/reviewed/panel/health';
            foreach ([$assets['js'], ...$assets['css']] as $url) {
                self::assertSame($expected, parse_url($url, PHP_URL_PATH));
                $this->assertPublicCompiledAsset($url);
            }
        }
        $this->getJson('/reviewed/panel/health')->assertOk()->assertHeader('Content-Type', 'application/json');
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

    private function reloadLegacyRouteTable(): void
    {
        $this->app['router']->setRoutes(new RouteCollection());
        require dirname(__DIR__, 2) . '/routes/updater.php';
        $this->removeRoutes(['updater.spa.entry', 'updater.spa.asset']);
    }

    private function removeRoutes(array $names): void
    {
        $routes = new RouteCollection();
        foreach ($this->app['router']->getRoutes() as $route) {
            if (!in_array($route->getName(), $names, true)) {
                $routes->add($route);
            }
        }
        $routes->refreshNameLookups();
        $this->app['router']->setRoutes($routes);
    }

    private function assertPublicCompiledAsset(string $url): void
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        self::assertDoesNotMatchRegularExpression('/\.(?:js|css)$/i', $path, 'Nginx static locations must not intercept bundle URLs.');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $file = $query[SpaAssets::QUERY_PARAMETER] ?? null;
        self::assertIsString($file);
        self::assertNotEmpty($file);
        $expectedPath = SpaAssets::directory() . '/' . $file;
        self::assertFileExists($expectedPath);
        $response = $this->get($path . '?' . http_build_query($query));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $mime = str_ends_with($file, '.css') ? 'text/css' : 'application/javascript';
        self::assertStringStartsWith($mime, (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $body = $this->binaryBody($response);
        self::assertSame(hash_file('sha256', $expectedPath), hash('sha256', $body));
        self::assertStringNotContainsString('id="updater-bootstrap"', $body);
        $head = $this->call('HEAD', $path . '?' . http_build_query($query))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith($mime, (string) $head->headers->get('Content-Type'));
    }

    private function binaryBody(TestResponse $response): string
    {
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        ob_start();
        $response->baseResponse->sendContent();
        return (string) ob_get_clean();
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


final class SpaHostRejectUnauthenticatedMiddleware
{
    public function handle($request, \Closure $next)
    {
        abort(403, 'Host authentication remains required.');
    }
}
