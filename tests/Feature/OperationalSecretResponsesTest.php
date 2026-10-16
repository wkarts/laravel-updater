<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Http\Controllers\OperationsController;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Support\Facades\Route;
use Mockery;
use Orchestra\Testbench\TestCase;

final class OperationalSecretResponsesTest extends TestCase
{
    private const SECRET = 'configuration-secret-in-legacy-failure';

    protected function getPackageProviders($app): array
    {
        return [UpdaterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'secure/panel');
        $app['config']->set('updater.ui.auth.enabled', false);
        $app['config']->set('updater.ui.middleware', ['web']);
        $app['config']->set('updater.sync_token', self::SECRET);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('p', 32)));
    }

    protected function defineRoutes($router): void
    {
        // Exercise the production controller and the provider's real presentation
        // middleware while keeping this fixture independent of login setup.
        Route::middleware('web')->get('/secure/panel/updates/progress/status', [OperationsController::class, 'updateProgressStatus'])
            ->name('updater.updates.progress.status');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $state = app(StateStore::class);
        $id = $state->createRun(['dry_run' => true]);
        // Simulate persisted state written by an older version, before redaction.
        $state->pdo()->prepare('UPDATE runs SET status=:status, error_json=:error, options_json=:options WHERE id=:id')->execute([
            ':status' => 'failed',
            ':error' => json_encode(['message' => 'Failure using ' . self::SECRET]),
            ':options' => json_encode(['dry_run' => true, 'note' => 'Failure using ' . self::SECRET]),
            ':id' => $id,
        ]);
        $state->addRunLog($id, 'error', 'Failure using ' . self::SECRET, ['note' => 'Failure using ' . self::SECRET]);
        $recovery = Mockery::mock(UpdateRecoveryManager::class);
        $recovery->shouldReceive('reconcile')->andReturn(['active' => false]);
        $this->app->instance(UpdateRecoveryManager::class, $recovery);
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('status')->andReturn(['enabled' => true, 'message' => 'Failure using ' . self::SECRET]);
        $kernel->shouldReceive('stateStore')->andReturn($state);
        $this->app->instance(UpdaterKernel::class, $kernel);
    }

    public function testProductionProgressAndWebStatusRedactConfiguredSecretsInUnstructuredMessages(): void
    {
        $this->getJson('/secure/panel/updates/progress/status')->assertOk()
            ->assertJsonPath('active', false)->assertJsonPath('run.status', 'failed')
            ->assertJsonPath('logs.0.message', 'Failure using [REDACTED]')
            ->assertJsonMissingPath('run.options_json')->assertJsonMissingPath('run.error_json')
            ->assertDontSee(self::SECRET, false);
        $this->getJson('/secure/panel/status')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('message', 'Failure using [REDACTED]')
            ->assertDontSee(self::SECRET, false);
    }

    public function testAuthenticatedApiStatusAndRunsRedactSecretsWithoutDependingOnSpaMiddleware(): void
    {
        $headers = ['X-Updater-Token' => self::SECRET];
        $this->getJson('/secure/panel/api/status', $headers)->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.message', 'Failure using [REDACTED]')->assertDontSee(self::SECRET, false);
        $runs = $this->getJson('/secure/panel/api/runs', $headers)->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('count', 1)->assertDontSee(self::SECRET, false);
        self::assertIsString($runs->json('data.0.options_json'));
        self::assertIsString($runs->json('data.0.error_json'));
        self::assertTrue(json_decode($runs->json('data.0.options_json'), true, 512, JSON_THROW_ON_ERROR)['dry_run']);
    }
}
