<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ApiTokenRegistry;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Support\Facades\Route;
use Mockery;
use Orchestra\Testbench\TestCase;

final class ApiCompatibilityTest extends TestCase
{
    private ManagerStore $manager;
    private ApiTokenRegistry $tokens;
    private StateStore $state;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'updater.sync_token' => 'legacy-test-secret', 'updater.git.default_update_mode' => 'merge']);
        $this->state = new StateStore(':memory:');
        $this->state->ensureSchema();
        $this->manager = new ManagerStore($this->state);
        $this->tokens = new ApiTokenRegistry($this->manager, $this->state);
        $this->app->instance(StateStore::class, $this->state);
        $this->app->instance(ManagerStore::class, $this->manager);
        $this->app->instance(ApiTokenRegistry::class, $this->tokens);
        Route::post('/custom/api/trigger', [UpdaterUiController::class, 'apiTrigger']);
        Route::get('/custom/api/status', [UpdaterUiController::class, 'apiStatus']);
        Route::get('/custom/api/runs', [UpdaterUiController::class, 'apiRuns']);
        Route::get('/custom/api/openapi.json', [UpdaterUiController::class, 'apiOpenApi']);
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('status')->andReturn(['enabled' => true]);
        $kernel->shouldReceive('stateStore')->andReturn($this->state);
        $this->app->instance(UpdaterKernel::class, $kernel);
    }

    public function testLegacyBothHeadersAndOldHashedTokensRemainAccepted(): void
    {
        $old = $this->manager->generateApiToken('old');
        $this->getJson('/custom/api/status', ['Authorization' => 'Bearer legacy-test-secret'])->assertOk()->assertJsonPath('data.enabled', true);
        $this->getJson('/custom/api/status', ['X-Updater-Token' => 'legacy-test-secret'])->assertOk();
        $this->getJson('/custom/api/status', ['Authorization' => 'Bearer ' . $old['token']])->assertOk();
        $this->manager->revokeApiToken($old['id']);
        $this->getJson('/custom/api/status', ['Authorization' => 'Bearer ' . $old['token']])->assertUnauthorized();
    }

    public function testNewTokensAreReadOnlyByDefaultAndNeverReturnedFromListing(): void
    {
        $new = $this->tokens->create('monitor');
        $this->getJson('/custom/api/status', ['Authorization' => 'Bearer ' . $new['token']])->assertOk();
        $this->app->instance(TriggerDispatcher::class, Mockery::mock(TriggerDispatcher::class)->shouldNotReceive('triggerUpdate')->getMock());
        $this->postJson('/custom/api/trigger', ['dry_run' => true], ['Authorization' => 'Bearer ' . $new['token']])->assertForbidden();
        $tokens = $this->tokens->tokens();
        self::assertSame(ApiTokenRegistry::READ_SCOPES, $tokens[0]['scopes']);
        self::assertNotEmpty($tokens[0]['last_used_at']);
        self::assertStringNotContainsString($new['token'], json_encode($tokens, JSON_THROW_ON_ERROR));
        $hash = $this->state->pdo()->query('SELECT token_hash FROM updater_api_tokens')->fetchColumn();
        self::assertNotSame($new['token'], $hash);
        self::assertTrue(password_verify($new['token'], $hash));
    }

    public function testDryRunBeforeCannotTriggerRealUpdateAndReplayDoesNotDispatchTwice(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $o): bool => $o['dry_run'] === true && !isset($o['pre_update_commands'])))->andReturn(42);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $headers = ['Authorization' => 'Bearer legacy-test-secret', 'Idempotency-Key' => 'release-action-123'];
        $payload = ['dry_run_before' => true, 'confirmation' => 'ATUALIZAR'];
        $this->postJson('/custom/api/trigger', $payload, $headers)->assertOk()->assertJsonPath('options.dry_run', true);
        $this->postJson('/custom/api/trigger', $payload, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('run_id', 42);
        $this->postJson('/custom/api/trigger', ['dry_run' => true, 'target_branch' => 'different'], $headers)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    }

    public function testRealUpdatesRequireExplicitConfirmationAndCannotApproveDryRunsByToken(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldNotReceive('triggerUpdate');
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $headers = ['Authorization' => 'Bearer legacy-test-secret'];
        $this->postJson('/custom/api/trigger', [], $headers)->assertStatus(422)->assertJsonPath('code', 'confirmation_required');
        $this->postJson('/custom/api/trigger', ['approval_run_id' => 1, 'confirmation' => 'ATUALIZAR'], $headers)->assertConflict()->assertJsonPath('code', 'approval_required');
    }

    public function testRemoteCommandsSqlAndSeedsAreRejectedWithoutDispatch(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldNotReceive('triggerUpdate');
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        foreach (['pre_update_commands', 'post_update_commands', 'command', 'commands', 'sql_patch', 'seed'] as $key) {
            $this->postJson('/custom/api/trigger', [$key => 'untrusted', 'dry_run' => true], ['X-Updater-Token' => 'legacy-test-secret'])->assertStatus(422);
        }
    }

    public function testLegacyDisabledSeedAndPatchFieldsRemainNoOps(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true && !isset($options['seed'], $options['sql_patch'])))->andReturn(45);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->postJson('/custom/api/trigger', ['dry_run' => true, 'seed' => false, 'sql_patch' => false], ['X-Updater-Token' => 'legacy-test-secret'])->assertOk()->assertJsonPath('run_id', 45);
    }

    public function testApplyScopeWithConfirmationQueuesRealUpdate(): void
    {
        $token = $this->tokens->create('deploy', ['updates:apply']);
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === false && $options['target_tag'] === 'v1.0.0-rc.1+build.42'))->andReturn(43);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->postJson('/custom/api/trigger', ['update_mode' => 'tag', 'target_tag' => 'v1.0.0-rc.1+build.42', 'confirmation' => 'ATUALIZAR'], ['Authorization' => 'Bearer ' . $token['token']])->assertOk()->assertJsonPath('run_id', 43)->assertJsonPath('options.dry_run', false);
    }

    public function testActionSimulateAlwaysSimulatesEvenWithApplyConfirmation(): void
    {
        $token = $this->tokens->create('preview', ['updates:simulate']);
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true))->andReturn(44);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->postJson('/custom/api/trigger', ['action' => 'simulate', 'dry_run' => false, 'confirmation' => 'ATUALIZAR'], ['X-Updater-Token' => $token['token']])->assertOk()->assertJsonPath('options.dry_run', true);
    }

    public function testRunReadsKeepJsonStringColumnsWhileRemovingSecrets(): void
    {
        $id = $this->state->createQueuedRun(['dry_run' => true]);
        // Legacy state rows may predate persistence sanitization.
        $this->state->pdo()->prepare('UPDATE runs SET options_json = ? WHERE id = ?')->execute([json_encode(['dry_run' => true, 'auth_password' => 'private-credential', 'message' => 'Failure with legacy-test-secret', 'repository' => 'https://user:secret@github.com/acme/project']), $id]);
        $response = $this->getJson('/custom/api/runs', ['X-Updater-Token' => 'legacy-test-secret'])->assertOk();
        $optionsJson = $response->json('data.0.options_json');
        self::assertIsString($optionsJson);
        self::assertStringNotContainsString('private-credential', $optionsJson);
        self::assertStringNotContainsString('user:secret', $optionsJson);
        self::assertStringNotContainsString('legacy-test-secret', $optionsJson);
        self::assertTrue(json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR)['dry_run']);
    }

    public function testApiRateLimitIsEnforcedPerToken(): void
    {
        $token = $this->tokens->create('limited');
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/custom/api/status', ['X-Updater-Token' => $token['token']])->assertOk();
        }
        $this->getJson('/custom/api/status', ['X-Updater-Token' => $token['token']])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function testOpenApiUsesConfiguredPrefixAndActualAdditivePaths(): void
    {
        config(['updater.ui.prefix' => 'custom']);
        $this->getJson('/custom/api/openapi.json', ['X-Updater-Token' => 'legacy-test-secret'])
            ->assertOk()->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('servers.0.url', 'http://localhost/custom')
            ->assertJsonPath('paths./api/status.get.operationId', 'status')
            ->assertJsonPath('paths./api/trigger.post.operationId', 'trigger')
            ->assertJsonPath('paths./api/webhooks/{endpointId}.post.operationId', 'webhook_receive');
    }
}
