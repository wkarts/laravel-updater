<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Middleware\UpdaterAuthorizeMiddleware;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Argws\LaravelUpdater\Webhooks\WebhookSignature;
use Argws\LaravelUpdater\Webhooks\WebhookStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

final class WebhookRoutesTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/updater_webhook_routes_' . bin2hex(random_bytes(8)) . '.sqlite';
        $state = new StateStore($this->path);
        $state->ensureSchema();
        $this->app->instance(StateStore::class, $state);
        $this->app['router']->aliasMiddleware('updater.auth', WebhookTestIdentity::class);
        $this->app['router']->aliasMiddleware('updater.authorize', UpdaterAuthorizeMiddleware::class);
        $this->app['config']->set('updater.ui.prefix', 'control-updates');
        $this->app['config']->set('updater.ui.enabled', true);
        $this->app['config']->set('updater.ui.auth.enabled', true);
        $this->app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $this->app['config']->set('app.cipher', 'AES-256-CBC');
        WebhookTestIdentity::$permissions = ['settings.manage'];
        require dirname(__DIR__, 2) . '/routes/webhooks.php';
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app->instance(UpdaterKernel::class, $this->createMock(UpdaterKernel::class));
        $this->app->instance(TriggerDispatcher::class, $this->createMock(TriggerDispatcher::class));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testCustomPrefixMachineEndpointRemainsIndependentAndAdminRequiresPermissions(): void
    {
        self::assertSame('control-updates/api/webhooks/{endpointId}', Route::getRoutes()->getByName('updater.api.webhooks.receive')->uri());
        self::assertNotContains('web', Route::getRoutes()->getByName('updater.api.webhooks.receive')->gatherMiddleware());
        self::assertContains('web', Route::getRoutes()->getByName('updater.settings.webhooks.save')->gatherMiddleware());
        $this->getJson('/control-updates/webhooks')->assertOk()->assertJsonPath('data.endpoints', []);
        WebhookTestIdentity::$permissions = ['dashboard.view'];
        $this->getJson('/control-updates/webhooks')->assertForbidden();
        $this->postJson('/control-updates/webhooks/endpoints', ['name' => 'blocked', 'direction' => 'incoming'])->assertForbidden();
    }

    public function testCreatedSecretsAreOneTimeAndRotationKeepsReadPayloadSafe(): void
    {
        $response = $this->postJson('/control-updates/webhooks/endpoints', ['name' => 'CI', 'direction' => 'incoming', 'actions' => ['simulate'], 'enabled' => true])->assertCreated();
        $secret = $response->json('secret');
        $id = $response->json('endpoint.id');
        self::assertSame(64, strlen($secret));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $listing = $this->getJson('/control-updates/webhooks')->assertOk();
        self::assertStringNotContainsString($secret, $listing->getContent());
        $rotated = $this->postJson('/control-updates/webhooks/endpoints/' . $id . '/rotate-secret')->assertOk();
        self::assertNotSame($secret, $rotated->json('secret'));
        $this->deleteJson('/control-updates/webhooks/endpoints/' . $id)->assertOk();
        $this->getJson('/control-updates/webhooks')->assertJsonPath('data.endpoints', []);
    }

    public function testEmptyOrMissingIncomingActionsCannotSilentlyGrantStatus(): void
    {
        $created = $this->postJson('/control-updates/webhooks/endpoints', [
            'name' => 'Only simulate', 'direction' => 'incoming', 'actions' => ['simulate'], 'enabled' => true,
        ])->assertCreated();
        $id = $created->json('endpoint.id');
        foreach ([['actions' => []], []] as $actions) {
            $this->postJson('/control-updates/webhooks/endpoints', [
                'id' => $id, 'name' => 'Only simulate', 'direction' => 'incoming', 'enabled' => true, ...$actions,
            ])->assertStatus(422)->assertJsonValidationErrors('actions');
            self::assertSame(['simulate'], $this->app->make(WebhookStore::class)->endpoint($id)['actions']);
        }
        $this->postJson('/control-updates/webhooks/endpoints', [
            'name' => 'Missing action', 'direction' => 'incoming',
        ])->assertStatus(422)->assertJsonValidationErrors('actions');
        $this->postJson('/control-updates/webhooks/endpoints', [
            'name' => 'Missing event', 'direction' => 'outgoing', 'url' => 'https://8.8.8.8/hook', 'events' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('events');
    }

    public function testSignedSimulationCannotBecomeRealUpdateAndRepeatDoesNotDispatchAgain(): void
    {
        $store = $this->app->make(WebhookStore::class);
        $endpoint = $store->saveEndpoint(['name' => 'simulate', 'direction' => 'incoming', 'events' => [], 'actions' => ['simulate'], 'enabled' => true]);
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects(self::once())->method('triggerUpdate')->with(self::callback(static fn (array $options): bool => $options === [
            'dry_run' => true, 'profile_id' => 4, 'triggered_via' => 'webhook', 'allow_http' => true,
        ]))->willReturn(18);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $body = '{"action":"simulate","profile_id":4}';
        $timestamp = (string) time();
        $eventId = bin2hex(random_bytes(16));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_UPDATER_TIMESTAMP' => $timestamp,
            'HTTP_X_UPDATER_EVENT_ID' => $eventId, 'HTTP_X_UPDATER_SIGNATURE' => WebhookSignature::sign($endpoint['secret'], $timestamp, $eventId, $body)];
        $url = '/control-updates/api/webhooks/' . $endpoint['endpoint']['id'];
        $this->call('POST', $url, [], [], [], $headers, $body)->assertStatus(202)->assertJsonPath('run_id', 18);
        $this->call('POST', $url, [], [], [], $headers, $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson($url, ['action' => 'apply'])->assertUnauthorized();
    }
}

class WebhookTestIdentity
{
    public static array $permissions = [];

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('updater_user', ['id' => 22, 'email' => 'admin@example.com', 'is_admin' => false, 'permissions' => self::$permissions]);

        return $next($request);
    }
}
