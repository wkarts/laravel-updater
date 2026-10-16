<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Middleware\UpdaterAuthorizeMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class OperationAuthorizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('updater.ui.auth.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware([OperationAuthorizationActorMiddleware::class, UpdaterAuthorizeMiddleware::class])->group(function (): void {
            Route::post('/approve', static fn () => 'authorized')->name('updater.runs.approve');
            Route::post('/security/git', static fn () => 'authorized')->name('updater.security.git.maintain');
            Route::post('/security/lock', static fn () => 'authorized')->name('updater.security.lock.clear');
        });
    }

    public function testRunViewerCannotApproveUpdatesThroughTheServer(): void
    {
        $this->app['config']->set('test_actor', ['is_admin' => false, 'permissions' => ['runs.view']]);
        $this->post('/approve')->assertForbidden();
    }

    public function testUpdateOperatorCanReachTheExistingPasswordProtectedApprovalAction(): void
    {
        $this->app['config']->set('test_actor', ['is_admin' => false, 'permissions' => ['updates.run']]);
        $this->post('/approve')->assertOk();
    }

    public function testAdminWithRestrictedPermissionsCannotBypassSecurityPermission(): void
    {
        $this->app['config']->set('test_actor', ['is_admin' => true, 'permissions' => ['dashboard.view']]);
        $this->post('/security/git')->assertForbidden();
        $this->post('/security/lock')->assertForbidden();
    }

    public function testLegacyAdministratorFallbackAndHostManagedAuthenticationArePreserved(): void
    {
        $this->app['config']->set('test_actor', ['is_admin' => true, 'permissions' => []]);
        $this->post('/security/lock')->assertOk();
        $this->app['config']->set('updater.ui.auth.enabled', false);
        $this->app['config']->set('test_actor', []);
        $this->post('/security/git')->assertOk();
    }
}

class OperationAuthorizationActorMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        $request->attributes->set('updater_user', config('test_actor', []));

        return $next($request);
    }
}
