<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Pipeline\Steps\GitUpdateStep;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class GitMutableTagCheckoutTest extends TestCase
{
    private string $directory;
    private ShellRunner $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/updater-mutable-tag-' . bin2hex(random_bytes(7));
        mkdir($this->directory, 0775, true);
        $this->shell = new ShellRunner();

        // Helpers como base_path/storage_path precisam de uma Application real.
        // Nenhum provider do ERP ou serviço externo é inicializado neste teste.
        $app = new Application($this->consumerPath());
        $app->useStoragePath($this->consumerPath() . '/storage');
        $app->instance('config', new Repository([
            'updater' => [
                'git' => [
                    'path' => $this->consumerPath(),
                    'remote' => 'origin',
                    'branch' => 'main',
                    'update_type' => 'git_tag',
                    'default_update_mode' => 'merge',
                    'tag' => 'ui-continuous',
                    'tag_fetch_depth' => 1,
                    'auto_stash' => true,
                ],
                'git_maintenance' => ['enabled' => false],
            ],
        ]));
        Container::setInstance($app);

        $this->git(['init', '--bare', $this->originPath()], $this->directory);
        $this->git(['init', '--initial-branch=main', $this->sourcePath()], $this->directory);
        $this->git(['config', 'user.name', 'Updater Test'], $this->sourcePath());
        $this->git(['config', 'user.email', 'updater@example.test'], $this->sourcePath());
        $this->commitSource('first');
        $this->git(['remote', 'add', 'origin', $this->originPath()], $this->sourcePath());
        $this->git(['push', '-u', 'origin', 'main'], $this->sourcePath());
        $this->git(['clone', '--branch', 'main', $this->originPath(), $this->consumerPath()], $this->directory);
        $this->git(['config', 'user.name', 'Updater Test'], $this->consumerPath());
        $this->git(['config', 'user.email', 'updater@example.test'], $this->consumerPath());

        // Reproduz #186: uma tag local antiga e uma tag remota homônima mais nova.
        $this->git(['tag', 'ui-continuous'], $this->consumerPath());
        $this->commitSource('second');
        $this->git(['tag', '-a', 'ui-continuous', '-m', 'channel release'], $this->sourcePath());
        $this->git(['tag', 'v1.2.0-rc.1'], $this->sourcePath());
        $this->git(['tag', 'v1.2.0'], $this->sourcePath());
        $this->git(['push', 'origin', 'main', 'refs/tags/ui-continuous', 'refs/tags/v1.2.0-rc.1', 'refs/tags/v1.2.0'], $this->sourcePath());
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        if (isset($this->directory) && is_dir($this->directory)) {
            (new Filesystem())->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function testDriverAppliesRemoteAnnotatedMovingTagAndKeepsLocalTagUntouched(): void
    {
        $oldTag = $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath());
        $expected = $this->sourceHead();
        $actual = $this->driver('ui-continuous')->update();

        self::assertSame($expected, $actual);
        self::assertSame($expected, $this->consumerHead());
        self::assertSame($oldTag, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
        self::assertSame($expected, $this->fetchedCommit('ui-continuous'));
    }

    public function testPipelineWithActiveSourceAppliesMovingTagAndPreservesEnvironment(): void
    {
        $old = $this->consumerHead();
        $expected = $this->sourceHead();
        file_put_contents($this->consumerPath() . '/.env', "APP_KEY=client-test-secret\n");
        file_put_contents($this->consumerPath() . '/.git/info/exclude', "\n.env\nstorage/\n");
        $step = $this->step('ui-continuous');
        $context = $this->context('ui-continuous');
        $step->handle($context);

        self::assertSame($old, $context['revision_before']);
        self::assertSame($expected, $context['revision_after']);
        self::assertSame($expected, $this->consumerHead());
        self::assertSame($old, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
        self::assertContains('tag solicitada: ui-continuous', $context['git_update_log']);
        self::assertSame("APP_KEY=client-test-secret\n", file_get_contents($this->consumerPath() . '/.env'));
    }

    public function testTagCanMoveAgainAndUpdaterAlwaysUsesRemoteCommit(): void
    {
        $oldTag = $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath());
        $first = $this->driver('ui-continuous')->update();
        $this->commitSource('third');
        $this->git(['tag', '-f', 'ui-continuous'], $this->sourcePath());
        $this->git(['push', 'origin', 'main', '+refs/tags/ui-continuous:refs/tags/ui-continuous'], $this->sourcePath());
        $second = $this->driver('ui-continuous')->update();

        self::assertNotSame($first, $second);
        self::assertSame($this->sourceHead(), $second);
        self::assertSame($oldTag, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
    }

    public function testPreReleaseNotPreviouslyFetchedCanBeAppliedThroughPipeline(): void
    {
        $oldTag = $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath());
        $step = $this->step('v1.2.0-rc.1');
        $context = $this->context('v1.2.0-rc.1');
        $step->handle($context);

        self::assertSame($this->sourceHead(), $context['revision_after']);
        self::assertSame($this->sourceHead(), $this->fetchedCommit('v1.2.0-rc.1'));
        self::assertSame($oldTag, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
    }

    public function testFixedTagCanBeAppliedAndRepeatedWithoutFalseFailure(): void
    {
        $step = $this->step('v1.2.0');
        $first = $this->context('v1.2.0');
        $step->handle($first);
        $second = $this->context('v1.2.0');
        $step->handle($second);

        self::assertSame($this->sourceHead(), $first['revision_after']);
        self::assertSame($first['revision_after'], $second['revision_after']);
        self::assertSame($second['revision_before'], $second['revision_after']);
    }

    public function testRollbackReturnsToPreviousCommitAfterTagCheckoutAndPruning(): void
    {
        app('config')->set('updater.git_maintenance.enabled', true);
        $old = $this->consumerHead();
        $step = $this->step('ui-continuous');
        $context = $this->context('ui-continuous');
        $step->handle($context);
        self::assertNotSame($old, $this->consumerHead());
        $step->rollback($context);

        self::assertSame($old, $this->consumerHead());
        self::assertSame("first\n", file_get_contents($this->consumerPath() . '/README.md'));
    }

    public function testMergeAndFfOnlyStillApplyBranchUpdates(): void
    {
        $step = $this->step('');
        $context = $this->context('', 'git_merge');
        $step->handle($context);
        self::assertSame($this->sourceHead(), $this->consumerHead());

        $this->commitSource('third');
        $this->git(['push', 'origin', 'main'], $this->sourcePath());
        app('config')->set('updater.git.default_update_mode', 'ff-only');
        $context = $this->context('', 'git_ff_only');
        $step->handle($context);
        self::assertSame($this->sourceHead(), $this->consumerHead());
    }

    public function testMergePreservesASeparateLocalCommit(): void
    {
        file_put_contents($this->consumerPath() . '/local.txt', "customer file\n");
        $this->git(['add', 'local.txt'], $this->consumerPath());
        $this->git(['commit', '-m', 'local commit'], $this->consumerPath());
        $old = $this->consumerHead();
        $step = $this->step('');
        $context = $this->context('', 'git_merge');
        $step->handle($context);

        self::assertSame("customer file\n", file_get_contents($this->consumerPath() . '/local.txt'));
        self::assertSame("second\n", file_get_contents($this->consumerPath() . '/README.md'));
        $this->git(['merge-base', '--is-ancestor', $old, 'HEAD'], $this->consumerPath());
        $this->git(['merge-base', '--is-ancestor', $this->sourceHead(), 'HEAD'], $this->consumerPath());
        self::assertNotSame($old, $this->consumerHead());
    }

    public function testAlternateBranchRemainsSelectable(): void
    {
        $this->git(['checkout', '-b', 'preview'], $this->sourcePath());
        $this->commitSource('preview');
        $this->git(['push', 'origin', 'preview'], $this->sourcePath());
        $step = $this->step('');
        $context = $this->context('', 'git_merge');
        $context['options']['target_branch'] = 'preview';
        $step->handle($context);

        self::assertSame($this->sourceHead(), $this->consumerHead());
        self::assertSame('preview', $this->git(['branch', '--show-current'], $this->consumerPath()));
    }

    public function testMissingRemoteTagNeverSilentlyUsesExistingLocalTag(): void
    {
        $this->git(['tag', 'local-only'], $this->consumerPath());
        $before = $this->consumerHead();
        try {
            $context = $this->context('local-only');
            $this->step('local-only')->handle($context);
            self::fail('Uma tag ausente no remoto não deve produzir sucesso.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('local-only', $exception->getMessage());
            self::assertSame($before, $this->consumerHead());
        }
    }

    public function testInvalidTagIsRejectedBeforeCheckout(): void
    {
        $before = $this->consumerHead();
        $context = $this->context('bad tag');
        try {
            $this->step('bad tag')->handle($context);
            self::fail('Referência inválida deve falhar.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('inválida', $exception->getMessage());
            self::assertSame($before, $this->consumerHead());
        }
    }

    private function context(string $tag, string $type = 'git_tag'): array
    {
        return ['run_id' => 186, 'options' => [
            'update_type' => $type, 'target_tag' => $tag, 'target_branch' => '',
            'force' => false, 'allow_dirty' => false,
        ]];
    }

    private function step(string $tag): GitUpdateStep
    {
        $manager = $this->getMockBuilder(ManagerStore::class)
            ->disableOriginalConstructor()->onlyMethods(['activeSource'])->getMock();
        $manager->method('activeSource')->willReturn([
            'id' => 1, 'name' => 'Test source', 'type' => 'git_merge',
            'branch' => 'main', 'repo_url' => $this->originPath(), 'auth_mode' => 'none',
        ]);
        return new GitUpdateStep($this->driver($tag), $manager, $this->shell);
    }

    private function driver(string $tag): GitDriver
    {
        app('config')->set('updater.git.tag', $tag);
        return new GitDriver($this->shell, ['path' => $this->consumerPath(), 'remote' => 'origin', 'branch' => 'main']);
    }

    private function commitSource(string $version): void
    {
        file_put_contents($this->sourcePath() . '/README.md', $version . "\n");
        $this->git(['add', 'README.md'], $this->sourcePath());
        $this->git(['commit', '-m', $version], $this->sourcePath());
    }

    private function fetchedCommit(string $tag): string
    {
        return $this->git(['rev-parse', GitDriver::remoteTagTargetRef($tag) . '^{commit}'], $this->consumerPath());
    }

    private function sourceHead(): string { return $this->git(['rev-parse', 'HEAD'], $this->sourcePath()); }
    private function consumerHead(): string { return $this->git(['rev-parse', 'HEAD'], $this->consumerPath()); }
    private function originPath(): string { return $this->directory . '/origin.git'; }
    private function sourcePath(): string { return $this->directory . '/source'; }
    private function consumerPath(): string { return $this->directory . '/consumer'; }

    private function git(array $args, string $cwd): string
    {
        $result = $this->shell->runOrFail(array_merge(['git'], $args), $cwd);
        return trim((string) $result['stdout']);
    }
}
