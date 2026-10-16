<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Http\Controllers\ManagerController;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\SpaPresenter;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\UpdaterServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

final class InstalledVersionStatusTest extends TestCase
{
    private string $checkout;
    private string $head;

    protected function getPackageProviders($app): array { return [UpdaterServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('updater.sqlite.path', ':memory:');
        $app['config']->set('updater.ui.prefix', 'version/panel');
        $app['config']->set('updater.ui.auth.enabled', false);
        $app['config']->set('updater.ui.middleware', ['web']);
        $app['config']->set('updater.auto_publish.enabled', false);
        $app['config']->set('updater.auto_publish.run_vendor_publish', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('v', 32)));
        $app['config']->set('updater.sync_token', 'status-test-token');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkout = sys_get_temp_dir() . '/updater-status-identity-' . bin2hex(random_bytes(7));
        mkdir($this->checkout, 0700, true);
        config(['updater.git.path' => $this->checkout]);
        $this->git(['init', '--initial-branch=main']);
        $this->git(['config', 'user.name', 'Identity Test']);
        $this->git(['config', 'user.email', 'identity@example.test']);
        file_put_contents($this->checkout . '/code.txt', 'installed beta');
        $this->git(['add', 'code.txt']);
        $this->git(['commit', '-m', 'installed']);
        $this->head = $this->git(['rev-parse', 'HEAD']);
        $metadata = ['schema_version' => 1, 'version' => 'v5.0.236-beta.53', 'base_stable_tag' => 'v5.0.235',
            'git_commit_sha' => $this->head, 'release_channel' => 'beta-continuous', 'build_number' => 53];
        $this->git(['tag', '-a', 'ui-continuous', '-m', json_encode($metadata)]);
        $this->git(['update-ref', 'refs/laravel-updater/targets/ui-continuous', 'refs/tags/ui-continuous']);
        $this->git(['tag', '-d', 'ui-continuous']);
        Route::get('/installed-status-api', [UpdaterUiController::class, 'apiStatus']);
        Route::get('/installed-check-api', [UpdaterUiController::class, 'apiCheck']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->checkout);
        parent::tearDown();
    }

    public function testKernelAndLegacyApiPreserveShaAndAddInstalledIdentityWithoutARemote(): void
    {
        $status = app(UpdaterKernel::class)->status();
        self::assertSame($this->head, $status['revision']);
        self::assertSame('5.0.236-beta.53', $status['installed_version']);
        self::assertSame('ui-continuous', $status['installed_release']['tag']);
        self::assertSame('v5.0.235', $status['installed_release']['base_stable_tag']);
        $this->getJson('/installed-status-api', ['X-Updater-Token' => 'status-test-token'])
            ->assertOk()->assertJsonPath('data.revision', $this->head)
            ->assertJsonPath('data.installed_version', '5.0.236-beta.53');
    }

    public function testVersionBarUsesTheRealTagInsteadOfTheLastRunSha(): void
    {
        $kernel = app(UpdaterKernel::class);
        $status = $kernel->status();
        $status['last_run'] = ['revision_after' => str_repeat('a', 40), 'status' => 'failed'];
        $bar = app(UpdaterUiController::class)->resolveVersionBarData($kernel, $status);
        self::assertSame('ui-continuous', $bar['application']['git_tag']);
        self::assertSame($this->head, $bar['application']['git_revision']);
        self::assertSame('5.0.236-beta.53', $bar['application']['installed_version']);
    }

    public function testApiCheckAddsIdentityWithoutChangingTheCurrentRevisionField(): void
    {
        $driver = $this->getMockBuilder(GitDriver::class)
            ->setConstructorArgs([new ShellRunner(), (array) config('updater.git')])
            ->onlyMethods(['statusUpdates'])->getMock();
        $driver->method('statusUpdates')->willReturn([
            'local' => $this->head, 'remote' => $this->head, 'has_updates' => false,
            'behind_by_commits' => 0, 'ahead_by_commits' => 0, 'latest_tag' => null,
        ]);
        $preflight = $this->createMock(PreflightChecker::class);
        $preflight->expects(self::once())->method('validate');
        $this->app->instance(CodeDriverInterface::class, $driver);
        $this->app->instance(PreflightChecker::class, $preflight);
        $this->getJson('/installed-check-api', ['X-Updater-Token' => 'status-test-token'])
            ->assertOk()->assertJsonPath('data.current_revision', $this->head)
            ->assertJsonPath('data.installed_version', '5.0.236-beta.53')
            ->assertJsonPath('data.installed_release.base_stable_tag', 'v5.0.235');
    }

    public function testUpdatesFallbackStillExposesInstalledVersionWhenTheRemoteCheckFails(): void
    {
        $controller = app(ManagerController::class);
        $method = new \ReflectionMethod($controller, 'buildUpdateStatusCheck');
        $status = $method->invoke($controller);
        self::assertTrue($status['status_unavailable']);
        self::assertSame($this->head, $status['current_revision']);
        self::assertSame('5.0.236-beta.53', $status['installed_version']);
    }

    public function testUnknownExternalRevisionDoesNotUseAnOldSuccessfulOrDryRunTarget(): void
    {
        file_put_contents($this->checkout . '/code.txt', 'external commit');
        $this->git(['commit', '-am', 'external']);
        $status = app(UpdaterKernel::class)->status();
        self::assertSame($this->git(['rev-parse', 'HEAD']), $status['revision']);
        self::assertNull($status['installed_version']);
        self::assertNull($status['installed_release']);
    }

    public function testRealSpaDashboardAndUpdatesKeepVersionBaseAndOriginalCommit(): void
    {
        $driver = $this->getMockBuilder(GitDriver::class)
            ->setConstructorArgs([new ShellRunner(), (array) config('updater.git')])
            ->onlyMethods(['statusUpdates', 'listTags'])->getMock();
        $driver->method('statusUpdates')->willThrowException(new \RuntimeException('Remote check unavailable'));
        $driver->method('listTags')->willReturn([]);
        $this->app->instance(CodeDriverInterface::class, $driver);
        $this->getJson('/version/panel', ['X-Updater-SPA' => '1'])
            ->assertOk()->assertJsonPath('page', 'dashboard')
            ->assertJsonPath('data.status.revision', $this->head)
            ->assertJsonPath('data.status.installed_version', '5.0.236-beta.53')
            ->assertJsonPath('data.status.installed_release.base_stable_tag', 'v5.0.235')
            ->assertJsonPath('data.versionBar.application.git_tag', 'ui-continuous');
        $this->getJson('/version/panel/updates', ['X-Updater-SPA' => '1'])
            ->assertOk()->assertJsonPath('page', 'sections.updates')
            ->assertJsonPath('data.statusCheck.current_revision', $this->head)
            ->assertJsonPath('data.statusCheck.installed_version', '5.0.236-beta.53')
            ->assertJsonPath('data.statusCheck.installed_release.tag', 'ui-continuous');
    }

    public function testPresentationWhitelistsTheInstalledReleaseForWebAndApiResponses(): void
    {
        $identity = app(UpdaterKernel::class)->status()['installed_release'];
        $identity['unrelated_metadata'] = ['private_information' => 'not-public'];
        $presenter = app(SpaPresenter::class);
        $clean = $presenter->sanitizeResponse(['installed_release' => $identity]);
        self::assertArrayNotHasKey('unrelated_metadata', $clean['installed_release']);
        self::assertSame('5.0.236-beta.53', $clean['installed_release']['version']);
    }

    private function git(array $arguments): string
    {
        $process = new Process(array_merge(['git'], $arguments), $this->checkout);
        $process->mustRun();
        return trim($process->getOutput());
    }
}
