<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Jobs\RunRollbackJob;
use Argws\LaravelUpdater\Jobs\RunUpdateJob;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Pipeline\Steps\GitUpdateStep;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Orchestra\Testbench\TestCase;

final class QueuedUpdateSelectionTest extends TestCase
{
    private ManagerStore $manager;
    private int $activeSourceId;
    private int $selectedSourceId;
    private int $activeProfileId;
    private int $selectedProfileId;

    protected function setUp(): void
    {
        parent::setUp();
        $state = new StateStore(':memory:');
        $state->ensureSchema();
        $this->manager = new ManagerStore($state);
        $this->app->instance(ManagerStore::class, $this->manager);
        foreach ([['active', 'main', 1], ['selected', 'release', 0]] as [$name, $branch, $active]) {
            $this->manager->createOrUpdateSource(['name' => $name, 'type' => 'github', 'repo_url' => 'https://github.com/acme/' . $name . '.git', 'branch' => $branch, 'active' => $active]);
            $id = (int) $state->pdo()->lastInsertId();
            if ($active) { $this->activeSourceId = $id; } else { $this->selectedSourceId = $id; }
        }
        $this->manager->createOrUpdateProfile(['name' => 'active', 'active' => 1, 'rollback_on_fail' => 1,
            'pre_update_commands' => 'php artisan active:before', 'post_update_commands' => 'php artisan active:after']);
        $this->activeProfileId = (int) $state->pdo()->lastInsertId();
        $this->manager->createOrUpdateProfile(['name' => 'selected', 'active' => 0, 'rollback_on_fail' => 0,
            'snapshot_include_vendor' => 1, 'snapshot_compression' => 'zip', 'seed' => 1,
            'pre_update_commands' => "# trusted profile\nphp artisan selected:before\nphp artisan selected:before",
            'post_update_commands' => 'php artisan selected:after']);
        $this->selectedProfileId = (int) $state->pdo()->lastInsertId();
    }

    public function testWorkerResolvesSelectedTrustedProfileWithoutChangingActiveSelections(): void
    {
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('run')->once()->with(Mockery::on(function (array $options): bool {
            self::assertSame($this->selectedSourceId, $options['source_id']);
            self::assertSame($this->selectedProfileId, $options['profile_id']);
            self::assertSame(['php artisan selected:before'], $options['pre_update_commands']);
            self::assertSame(['php artisan selected:after'], $options['post_update_commands']);
            self::assertFalse($options['rollback_on_fail']);
            self::assertTrue($options['snapshot_include_vendor']);
            self::assertSame('zip', $options['snapshot_compression']);
            self::assertFalse($options['seed']);
            self::assertFalse($options['dry_run']);
            self::assertArrayNotHasKey('auth_password', $options);
            return true;
        }))->andReturn([]);
        (new RunUpdateJob([
            'source_id' => $this->selectedSourceId, 'profile_id' => $this->selectedProfileId,
            'dry_run' => false, 'pre_update_commands' => ['untrusted remote command'],
            'post_update_commands' => ['untrusted remote command'], 'rollback_on_fail' => true,
            'snapshot_include_vendor' => false, 'snapshot_compression' => 'tar.gz',
        ]))->handle($kernel, $this->manager);
        self::assertSame($this->activeSourceId, (int) $this->manager->activeSource()['id']);
        self::assertSame($this->activeProfileId, (int) $this->manager->activeProfile()['id']);
    }

    public function testWorkerHonorsExplicitSimulationDecisionAndUsesProfileOnlyWhenAbsent(): void
    {
        $this->manager->createOrUpdateProfile(['name' => 'selected', 'dry_run' => 1], $this->selectedProfileId);
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('run')->once()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === false))->andReturn([]);
        $kernel->shouldReceive('run')->twice()->with(Mockery::on(static fn (array $options): bool => $options['dry_run'] === true))->andReturn([]);
        (new RunUpdateJob(['profile_id' => $this->selectedProfileId, 'dry_run' => false]))->handle($kernel, $this->manager);
        (new RunUpdateJob(['profile_id' => $this->activeProfileId, 'dry_run' => true]))->handle($kernel, $this->manager);
        (new RunUpdateJob(['profile_id' => $this->selectedProfileId]))->handle($kernel, $this->manager);
    }

    public function testDeletedSelectedSourceFailsWithoutFallingBackToActive(): void
    {
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldNotReceive('run');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fonte selecionada');
        (new RunUpdateJob(['source_id' => 999, 'profile_id' => $this->selectedProfileId]))->handle($kernel, $this->manager);
    }

    public function testDeletedSelectedProfileFailsWithoutFallingBackToActive(): void
    {
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldNotReceive('run');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('perfil selecionado');
        (new RunUpdateJob(['source_id' => $this->selectedSourceId, 'profile_id' => 999]))->handle($kernel, $this->manager);
    }

    public function testGitStepUsesImmutableRunSourceIdWhenAnotherSourceIsActive(): void
    {
        $driver = Mockery::mock(CodeDriverInterface::class);
        $shell = Mockery::mock(ShellRunner::class);
        $shell->shouldReceive('run')->once()->with(['git', 'check-ref-format', 'refs/heads/release'], '/tmp', ['GIT_TERMINAL_PROMPT' => '0'])->andReturn(['exit_code' => 0]);
        $shell->shouldNotReceive('runOrFail');
        $step = new GitUpdateStep($driver, $this->manager, $shell);
        $method = (new \ReflectionClass($step))->getMethod('prepareSourceContext');
        $context = ['options' => ['source_id' => $this->selectedSourceId, 'dry_run' => true]];
        $method->invokeArgs($step, [&$context, 'git_merge', '', '', '/tmp', ['GIT_TERMINAL_PROMPT' => '0'], true]);
        self::assertSame($this->selectedSourceId, $context['source_id']);
        self::assertSame('selected', $context['source_name']);
        self::assertSame('release', config('updater.git.branch'));
        self::assertSame($this->activeSourceId, (int) $this->manager->activeSource()['id']);
    }

    public function testLaravelBusPushesUpdateAndRollbackJobsToQueueWithoutRunningKernel(): void
    {
        Queue::fake();
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldNotReceive('run');
        $kernel->shouldNotReceive('rollback');
        $this->app->instance(UpdaterKernel::class, $kernel);
        Bus::dispatch(new RunUpdateJob(['source_id' => $this->selectedSourceId, 'profile_id' => $this->selectedProfileId]));
        Bus::dispatch(new RunRollbackJob());
        Queue::assertPushed(RunUpdateJob::class, 1);
        Queue::assertPushed(RunRollbackJob::class, 1);
    }

    public function testLegacySingleArgumentHandleResolvesManagerFromApplication(): void
    {
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('run')->once()->with(Mockery::on(fn (array $options): bool => $options['profile_id'] === $this->selectedProfileId && $options['pre_update_commands'] === ['php artisan selected:before']))->andReturn([]);
        (new RunUpdateJob(['profile_id' => $this->selectedProfileId, 'source_id' => $this->selectedSourceId, 'dry_run' => true]))->handle($kernel);
    }

    public function testTrustedShellCaseSyntaxAndExplicitSeedOptionArePreserved(): void
    {
        $command = 'case "$MODE" in live) php artisan trusted:prepare ;; esac';
        $profile = $this->manager->findProfile($this->selectedProfileId);
        $this->manager->createOrUpdateProfile([...$profile, 'pre_update_commands' => $command], $this->selectedProfileId);
        $kernel = Mockery::mock(UpdaterKernel::class);
        $kernel->shouldReceive('run')->once()->with(Mockery::on(static fn (array $options): bool => $options['pre_update_commands'] === [$command] && $options['seed'] === true))->andReturn([]);
        (new RunUpdateJob(['profile_id' => $this->selectedProfileId, 'dry_run' => true, 'seed' => true]))->handle($kernel, $this->manager);
    }
}
