<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\Totp;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Orchestra\Testbench\TestCase;

final class SpaAuthenticationFlowTest extends TestCase
{
    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'secure/updater');
        $app['config']->set('updater.ui.auth.enabled', true);
        $app['config']->set('updater.ui.auth.auto_provision_admin', false);
        $app['config']->set('updater.ui.auth.2fa.enabled', true);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        // Force real CSRF verification; Laravel normally bypasses it during tests.
        $this->app->bind(VerifyCsrfToken::class, SpaEnforcedCsrf::class);
        $this->app->bind('Orchestra\\Testbench\\Http\\Middleware\\VerifyCsrfToken', SpaEnforcedCsrf::class);
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, SpaEnforcedCsrf::class);
        $store = app(StateStore::class);
        $kernel = $this->createMock(UpdaterKernel::class);
        $kernel->method('stateStore')->willReturn($store);
        $kernel->method('status')->willReturn(['enabled' => true, 'mode' => 'inplace', 'channel' => 'stable', 'revision' => 'fixture-revision', 'last_run' => null]);
        $kernel->method('check')->willReturn(['current_revision' => 'fixture-revision', 'remote' => 'fixture-revision', 'has_updates' => false]);
        $this->app->instance(UpdaterKernel::class, $kernel);
        $shell = $this->createMock(ShellRunner::class);
        $shell->method('run')->willReturn(['exit_code' => 0, 'stdout' => '', 'stderr' => '']);
        $this->app->instance(ShellRunner::class, $shell);
        $this->app->instance(CodeDriverInterface::class, $this->createMock(CodeDriverInterface::class));
    }

    private function csrf(): string
    {
        $response = $this->getJson('/secure/updater/login', ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('page', 'login');
        $token = $response->json('context.csrfToken');
        self::assertNotEmpty($token);
        return $token;
    }

    public function testLoginRejectsMissingCsrfAndAuthenticatedCookieGuardsRealPages(): void
    {
        app(AuthStore::class)->createUser('operator@example.com', 'safe-test-password', true, 'Operator');
        $token = $this->csrf();
        $this->postJson('/secure/updater/login', ['email' => 'operator@example.com', 'password' => 'safe-test-password'], ['X-Updater-SPA' => '1'])->assertStatus(419);
        $login = $this->postJson('/secure/updater/login', ['_token' => $token, 'email' => 'operator@example.com', 'password' => 'safe-test-password'], ['X-Updater-SPA' => '1'])->assertRedirect('/secure/updater');
        $session = $login->getCookie('updater_session')->getValue();
        self::assertNotNull(app(AuthStore::class)->findValidSession($session));
        $this->withCookie('updater_session', $session)->getJson('/secure/updater/profile', ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('page', 'profile')->assertJsonPath('data.user.email', 'operator@example.com');
        $this->withCookie('updater_session', $session)->postJson('/secure/updater/logout', ['_token' => $token], ['X-Updater-SPA' => '1'])->assertRedirect('/secure/updater/login');
        self::assertNull(app(AuthStore::class)->findValidSession($session));
    }

    public function testTwoFactorLoginUsesPendingSessionAndNeverReturnsTheExistingSecret(): void
    {
        $auth = app(AuthStore::class);
        $id = $auth->createUser('twofactor@example.com', 'safe-test-password', true, 'Two factor');
        $secret = 'JBSWY3DPEHPK3PXP';
        $auth->updateTotp($id, $secret, true);
        $token = $this->csrf();
        $this->postJson('/secure/updater/login', ['_token' => $token, 'email' => 'twofactor@example.com', 'password' => 'safe-test-password'], ['X-Updater-SPA' => '1'])->assertRedirect('/secure/updater/2fa');
        $this->getJson('/secure/updater/2fa', ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('page', 'twofactor')->assertDontSee($secret, false);
        $this->postJson('/secure/updater/2fa', ['_token' => $token, 'code' => 'invalid-code'], ['X-Updater-SPA' => '1'])->assertRedirect()->assertSessionHasErrors('code');
        $login = $this->postJson('/secure/updater/2fa', ['_token' => $token, 'code' => app(Totp::class)->code($secret)], ['X-Updater-SPA' => '1'])->assertRedirect('/secure/updater');
        $session = $login->getCookie('updater_session')->getValue();
        $this->withCookie('updater_session', $session)->getJson('/secure/updater/profile', ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('data.user.totp_enabled', true)->assertJsonMissingPath('data.pendingTotpSecret')->assertJsonMissingPath('data.otpauthUri')->assertDontSee($secret, false);
    }

    public function testAllPrincipalGetDestinationsResolveToTheirVuePagesAfterRealLogin(): void
    {
        app(AuthStore::class)->createUser('admin@example.com', 'safe-test-password', true, 'Administrator');
        $token = $this->csrf();
        $login = $this->postJson('/secure/updater/login', ['_token' => $token, 'email' => 'admin@example.com', 'password' => 'safe-test-password'])->assertRedirect('/secure/updater');
        $this->withCookie('updater_session', $login->getCookie('updater_session')->getValue());
        foreach ([
            '' => 'dashboard', '/updates' => 'sections.updates', '/runs' => 'sections.runs', '/sources' => 'sections.sources',
            '/backups' => 'sections.backups', '/logs' => 'sections.logs', '/security' => 'sections.security',
            '/seeds' => 'sections.seeds', '/migrations' => 'sections.migrations', '/users' => 'users.index',
            '/users/create' => 'users.create', '/profiles' => 'profiles.index', '/profiles/create' => 'profiles.create',
            '/settings' => 'settings.index', '/settings/env' => 'settings.env', '/api-docs' => 'api.docs', '/webhooks' => 'webhooks.index',
        ] as $path => $page) {
            $this->getJson('/secure/updater' . $path, ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('page', $page);
        }
    }

    public function testDetailAndEditDestinationsKeepVueKeysAndApprovalContext(): void
    {
        $auth = app(AuthStore::class);
        $id = $auth->createUser('details@example.com', 'safe-test-password', true, 'Administrator');
        $token = $this->csrf();
        $login = $this->postJson('/secure/updater/login', ['_token' => $token, 'email' => 'details@example.com', 'password' => 'safe-test-password'])->assertRedirect('/secure/updater');
        $this->withCookie('updater_session', $login->getCookie('updater_session')->getValue());
        $state = app(StateStore::class);
        $manager = app(\Argws\LaravelUpdater\Support\ManagerStore::class);
        $manager->createOrUpdateProfile(['name' => 'Fixture profile']);
        $profile = $manager->profiles()[0]['id'];
        $run = $state->createRun(['dry_run' => true, 'password' => 'never-expose-this']);
        $state->addMigrationAttempt($run, '2026_10_16_fixture_migration', 'failed');
        $state->pdo()->exec("INSERT INTO updater_backups(type,path,created_at)VALUES('database','/fixture/archive.sql','2026-10-16T00:00:00Z')");
        $backup = $state->pdo()->lastInsertId();
        $this->withSession(['updater_pending_approval_' . $run => ['target_tag' => 'v1.0.0-rc.1']]);
        foreach ([
            '/users/' . $id . '/edit' => 'users.edit', '/profiles/' . $profile . '/edit' => 'profiles.edit',
            '/runs/' . $run => 'runs.show', '/backups/' . $backup . '/restore' => 'backups.restore',
            '/migrations/2026_10_16_fixture_migration' => 'sections.migrations-show',
        ] as $path => $page) {
            $this->getJson('/secure/updater' . $path, ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('page', $page)->assertDontSee('never-expose-this', false);
        }
        $this->getJson('/secure/updater/runs/' . $run, ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('data.pendingApproval', true)->assertJsonMissingPath('data.run.options_json');
    }

    public function testCompiledRouteCacheKeepsNewUiAndApiRoutesDistinct(): void
    {
        $collection = $this->app['router']->getRoutes();
        $this->app['router']->setCompiledRoutes($collection->compile());
        $this->getJson('/secure/updater/login', ['X-Updater-SPA' => '1'])->assertOk()->assertJsonPath('page', 'login');
        $this->getJson('/secure/updater/api/status', ['X-Updater-SPA' => '1'])->assertUnauthorized()->assertJsonMissingPath('page');
        self::assertSame('secure/updater/api-docs', $this->app['router']->getRoutes()->getByName('updater.settings.api-docs')->uri());
        self::assertSame('secure/updater/webhooks', $this->app['router']->getRoutes()->getByName('updater.settings.webhooks.index')->uri());
    }
}

final class SpaEnforcedCsrf extends VerifyCsrfToken
{
    protected function runningUnitTests() { return false; }
}
