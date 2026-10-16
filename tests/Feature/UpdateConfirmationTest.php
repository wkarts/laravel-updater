<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Http\Controllers\OperationsController;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Http\Controllers\AuthController;
use Argws\LaravelUpdater\Http\Controllers\ManagerController;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\BackupCloudUploader;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
use Argws\LaravelUpdater\Support\ReleaseNotesResolver;
use Argws\LaravelUpdater\Support\Totp;
use Argws\LaravelUpdater\Support\UiPermission;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Monolog\Logger;
use Orchestra\Testbench\TestCase;

class UpdateConfirmationTest extends TestCase
{
    private StateStore $state;
    private ManagerStore $manager;
    private string $path;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('updater', ['snapshot' => ['compression' => 'zip', 'include_vendor' => false]]);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Route::post('/updates/execute', [OperationsController::class, 'executeUpdate']);
            Route::post('/runs/{id}/approve', function (int $id, Request $request, OperationsController $controller, TriggerDispatcher $dispatcher) {
                $request->attributes->set('updater_user', ['id' => 1]);
                return $controller->approveAndExecute($id, $request, $dispatcher);
            });
            Route::post('/migrations/reapply', function (Request $request, OperationsController $controller) {
                $request->attributes->set('updater_user', ['id' => 1, 'email' => 'operator@example.test']);
                return $controller->reapplyMigration($request);
            });
            Route::post('/backups/{id}/restore', function (int $id, Request $request, OperationsController $controller) {
                $request->attributes->set('updater_user', ['id' => 1]);
                return $controller->restoreBackup($id, $request);
            });
            Route::post('/legacy-trigger', [UpdaterUiController::class, 'triggerUpdate']);
            Route::get('/runs/{id}', static fn () => 'run')->name('updater.runs.show');
            Route::get('/{section}', static fn () => 'screen')->name('updater.section');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/updater-confirm-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->state = new StateStore($this->path);
        $this->state->ensureSchema();
        $this->manager = new ManagerStore($this->state);
        $this->manager->createOrUpdateProfile(['name' => 'Primary', 'active' => 1]);
        $this->manager->createOrUpdateSource(['name' => 'Primary', 'type' => 'git_merge', 'repo_url' => 'https://example.test/repo.git', 'active' => 1]);
        $this->manager->createUser(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'correct-password', 'is_admin' => 1]);
        $this->app->instance(ManagerStore::class, $this->manager);
        $this->app->instance(StateStore::class, $this->state);
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('stateStore')->andReturn($this->state);
        $this->app->instance(UpdaterKernel::class, $kernel);
        $this->app['view']->addNamespace('laravel-updater', __DIR__ . '/../../resources/views');
        foreach ([BackupDriverInterface::class, ShellRunner::class, FileManager::class, ArchiveManager::class, BackupCloudUploader::class, UpdateRecoveryManager::class] as $class) {
            $this->app->instance($class, Mockery::mock($class));
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testApplyWithDryRunBeforeAlwaysDispatchesOnlyASimulation(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true))->andReturn(null);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);

        $this->from('/updates')->post('/updates/execute', $this->payload(['action' => 'apply', 'dry_run_before' => true]))
            ->assertRedirect('/updates')->assertSessionHasNoErrors()->assertSessionHas('status', 'Dry-run disparado. Aguarde e confira em Execuções para aprovar.');
    }

    public function testSimulateRemainsDryRunEvenWhenDryRunCheckboxIsFalse(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true))->andReturn(null);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->from('/updates')->post('/updates/execute', $this->payload(['action' => 'simulate', 'dry_run_before' => false]))->assertSessionHasNoErrors();
    }

    public function testRealApplyIsRejectedBeforeDispatchWithoutExplicitConfirmation(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldNotReceive('triggerUpdate');
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->from('/updates')->post('/updates/execute', $this->payload(['action' => 'apply', 'dry_run_before' => false]))
            ->assertRedirect('/updates')->assertSessionHasErrors('confirm_apply');
    }

    public function testRealApplyRequiresAnExplicitActionAndDoesNotDefaultToApply(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldNotReceive('triggerUpdate');
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->from('/updates')->post('/updates/execute', $this->payload(['dry_run_before' => false]))->assertSessionHasErrors('action');
    }

    public function testExplicitlyConfirmedApplyCannotReceiveRemoteCommands(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === false && !isset($options['pre_update_commands']) && !isset($options['post_update_commands'])))->andReturn(41);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->post('/updates/execute', $this->payload(['action' => 'apply', 'dry_run_before' => false, 'confirm_apply' => 'ATUALIZAR', 'pre_update_commands' => 'untrusted remote command']))
            ->assertRedirect('/updates')->assertSessionHasNoErrors();
    }

    public function testUnfinishedSimulationCannotBeApproved(): void
    {
        $id = $this->state->createRun(['dry_run' => true]);
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldNotReceive('triggerUpdate');
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->from('/updates')->withSession(['updater_pending_approval_' . $id => $this->pending()])
            ->post('/runs/' . $id . '/approve', ['password' => 'correct-password', 'confirm_apply' => 'ATUALIZAR'])
            ->assertSessionHasErrors('approval');
    }

    public function testApprovalRequiresPasswordAndConfirmationAndReactivatesSimulatedConfiguration(): void
    {
        $id = $this->state->createRun(['dry_run' => true]);
        $this->state->finishRun($id, []);
        $this->manager->createOrUpdateProfile(['name' => 'Different', 'active' => 1]);
        $this->manager->createOrUpdateSource(['name' => 'Different', 'type' => 'git_merge', 'repo_url' => 'https://example.test/other.git', 'active' => 1]);
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(fn (array $options): bool => $options['dry_run'] === false && $options['profile_id'] === 1 && $options['source_id'] === 1 && (int) $this->manager->activeProfile()['id'] === 1 && (int) $this->manager->activeSource()['id'] === 1))->andReturn(42);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->withSession(['updater_pending_approval_' . $id => $this->pending()])
            ->post('/runs/' . $id . '/approve', ['password' => 'correct-password', 'confirm_apply' => 'ATUALIZAR'])
            ->assertRedirect('/updates')->assertSessionHasNoErrors()->assertSessionMissing('updater_pending_approval_' . $id);
    }

    public function testDryRunProducedByRealKernelCanBeApprovedAfterConfirmation(): void
    {
        $driver = Mockery::mock(CodeDriverInterface::class);
        $driver->shouldNotReceive('statusUpdates');
        $driver->shouldReceive('currentRevision')->once()->andReturn('current-revision');
        $preflight = Mockery::mock(PreflightChecker::class);
        $preflight->shouldReceive('report')->once()->andReturn([]);
        $recovery = Mockery::mock(UpdateRecoveryManager::class);
        $recovery->shouldReceive('registerFatalGuard')->once();
        $recovery->shouldReceive('disarmFatalGuard')->once();
        $logger = new Logger('approval-regression');
        $kernel = new UpdaterKernel(new EnvironmentDetector(), new UpdatePipeline([], $logger, $this->state), $driver, $preflight, $this->state, $logger, new RunReportMailer(), $recovery);
        $context = $kernel->run(['dry_run' => true, 'profile_id' => 1, 'source_id' => 1]);
        $id = (int) $context['run_id'];
        $this->assertSame('DRY_RUN', $this->state->findRun($id)['status']);

        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === false))->andReturn(43);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->withSession(['updater_pending_approval_' . $id => $this->pending()])
            ->post('/runs/' . $id . '/approve', ['password' => 'correct-password', 'confirm_apply' => 'ATUALIZAR'])
            ->assertRedirect('/updates')->assertSessionHasNoErrors()->assertSessionMissing('updater_pending_approval_' . $id);
    }

    public function testFailedRunsAndRealRunsCannotMasqueradeAsApprovedSimulations(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldNotReceive('triggerUpdate');
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        foreach ([['failed', true], ['cancelled', true], ['success', false], ['DRY_RUN', false]] as [$status, $dryRun]) {
            $id = $this->state->createRun(['dry_run' => $dryRun]);
            $this->state->updateRunStatus($id, $status);
            $this->from('/updates')->withSession(['updater_pending_approval_' . $id => $this->pending()])
                ->post('/runs/' . $id . '/approve', ['password' => 'correct-password', 'confirm_apply' => 'ATUALIZAR'])
                ->assertSessionHasErrors('approval');
        }
    }

    public function testSettingsNeverExposeStoredSecretsThroughValuesDefaultsOrEnvironmentFields(): void
    {
        $this->app['config']->set('updater.sync_token', 'config-secret-hidden');
        $this->app['config']->set('updater.ui.auth.default_password', 'default-secret-hidden');
        $this->manager->saveBackupUploadSettings(['provider' => 's3', 's3' => ['secret_key' => 'cloud-secret-hidden']]);
        $controller = new ManagerController($this->manager, new UiPermission(), new ReleaseNotesResolver());
        $configData = $controller->settingsIndex()->getData();
        $this->assertStringNotContainsString('config-secret-hidden', json_encode($configData));
        $this->assertStringNotContainsString('default-secret-hidden', json_encode($configData));
        $this->assertStringNotContainsString('cloud-secret-hidden', json_encode($configData));
        $tokenField = array_values(array_filter($configData['updaterConfigFields'], static fn (array $row): bool => $row['config'] === 'updater.sync_token'))[0];
        $this->assertTrue($tokenField['configured']);
        $this->assertSame('', $tokenField['value']);
        $this->assertNull($tokenField['default']);

        $base = $this->app->basePath();
        $temporary = sys_get_temp_dir() . '/updater-env-read-' . bin2hex(random_bytes(8));
        mkdir($temporary);
        file_put_contents($temporary . '/.env', "UPDATER_SYNC_TOKEN=environment-secret-hidden\n");
        try {
            $this->app->setBasePath($temporary);
            $envData = $controller->settingsEnvIndex()->getData();
            $this->assertStringNotContainsString('environment-secret-hidden', json_encode($envData));
            $this->assertStringNotContainsString('config-secret-hidden', json_encode($envData));
            $this->assertStringNotContainsString('default-secret-hidden', json_encode($envData));
        } finally {
            $this->app->setBasePath($base);
            unlink($temporary . '/.env');
            rmdir($temporary);
        }
    }

    public function testEnabledTwoFactorNeverRevealsExistingSecretAndRecoveryCodesAreOneTime(): void
    {
        $auth = new AuthStore($this->state);
        $auth->updateTotp(1, 'JBSWY3DPEHPK3PXP', true);
        $request = Request::create('/profile');
        $request->attributes->set('updater_user', $this->manager->findUser(1));
        $request->setLaravelSession($this->app['session.store']);
        $request->session()->put('updater_new_recovery_codes', ['ONE-TIME-RECOVERY']);
        $request->session()->put('updater_totp_secret', 'OLD-SESSION-SECRET');
        $controller = new AuthController($auth, new Totp(), $this->manager);
        $data = $controller->profile($request)->getData();
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', json_encode($data));
        $this->assertStringNotContainsString('OLD-SESSION-SECRET', json_encode($data));
        $this->assertSame('', $data['pendingTotpSecret']);
        $this->assertSame('', $data['otpauthUri']);
        $this->assertSame('', $data['qrcodeDataUri']);
        $this->assertSame(['ONE-TIME-RECOVERY'], $data['newRecoveryCodes']);
        $this->assertSame([], $controller->profile($request)->getData()['newRecoveryCodes']);
    }

    public function testMigrationReapplyRequiresMeaningfulReasonBeforeAnyQueueOrExecution(): void
    {
        foreach (['', '    ', 'ab'] as $reason) {
            $this->postJson('/migrations/reapply', ['migration' => 'example_migration', 'reason' => $reason, 'action_type' => 'queue'])
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->assertSame(0, (int) $this->state->pdo()->query('SELECT COUNT(*) FROM updater_migration_reapply_queue')->fetchColumn());
        $this->assertSame(0, (int) $this->state->pdo()->query('SELECT COUNT(*) FROM runs')->fetchColumn());
    }

    public function testMigrationReapplyRequiresExplicitAllowedActionBeforeAnyQueueOrExecution(): void
    {
        $this->postJson('/migrations/reapply', ['migration' => 'example_migration', 'reason' => 'Correção de inconsistência'])
            ->assertUnprocessable()->assertJsonValidationErrors('action_type');
        $this->postJson('/migrations/reapply', ['migration' => 'example_migration', 'reason' => 'Correção de inconsistência', 'action_type' => 'arbitrary'])
            ->assertUnprocessable()->assertJsonValidationErrors('action_type');
        $this->assertSame(0, (int) $this->state->pdo()->query('SELECT COUNT(*) FROM updater_migration_reapply_queue')->fetchColumn());
        $this->assertSame(0, (int) $this->state->pdo()->query('SELECT COUNT(*) FROM runs')->fetchColumn());
    }

    public function testLegacyWebTriggerHonorsSimulationAndCannotApplyWithoutConfirmation(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true))->andReturn(null);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->from('/updates')->post('/legacy-trigger', ['action' => 'apply', 'dry_run_before' => true])->assertSessionHasNoErrors();
        $this->from('/updates')->post('/legacy-trigger', ['action' => 'apply', 'dry_run_before' => false])->assertSessionHasErrors('confirm_apply');
    }

    public function testLegacyWebSimulationCannotBeConvertedToApplyOrReceiveUntrustedCommands(): void
    {
        $dispatcher = Mockery::mock(TriggerDispatcher::class);
        $dispatcher->shouldReceive('triggerUpdate')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true && $options['pre_update_commands'] === [] && $options['post_update_commands'] === []))->andReturn(null);
        $this->app->instance(TriggerDispatcher::class, $dispatcher);
        $this->from('/updates')->post('/legacy-trigger', [
            'action' => 'simulate', 'dry_run_before' => false, 'dry_run' => false, 'confirm_apply' => 'ATUALIZAR',
            'pre_update_commands' => 'untrusted input', 'post_update_commands' => 'untrusted input',
        ])->assertSessionHasNoErrors();
    }

    public function testRestoreStillRequiresTypedConfirmationAndCorrectPasswordBeforeAnyOperation(): void
    {
        $this->postJson('/backups/1/restore', ['confirmacao' => 'INVALID', 'password' => 'correct-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmacao');
        $this->postJson('/backups/1/restore', ['confirmacao' => 'RESTAURAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->from('/updates')->post('/backups/1/restore', ['confirmacao' => 'RESTAURAR', 'password' => 'incorrect-password'])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, (int) $this->state->pdo()->query('SELECT COUNT(*) FROM runs')->fetchColumn());
    }

    private function payload(array $overrides): array
    {
        return array_merge(['profile_id' => 1, 'source_id' => 1, 'update_mode' => 'merge'], $overrides);
    }

    private function pending(): array
    {
        return ['profile_id' => 1, 'source_id' => 1, 'update_type' => 'git_merge', 'target_tag' => '', 'target_branch' => 'main'];
    }
}
