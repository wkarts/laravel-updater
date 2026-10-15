<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Pipeline\Steps\GitUpdateStep;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class GitTagSafetyTest extends TestCase
{
    private string $root;
    private ShellRunner $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/updater-tag-safety-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        $this->shell = new ShellRunner();
        $app = new Application($this->path('client'));
        $app->instance('config', new Repository(['updater' => [
            'git' => [
                'path' => $this->path('client'), 'remote' => 'origin', 'branch' => 'main',
                'update_type' => 'git_tag', 'tag' => 'channel', 'default_update_mode' => 'merge',
            ],
            'git_maintenance' => ['enabled' => true],
        ]]));
        Container::setInstance($app);
        $this->git(['init', '--bare', $this->path('origin.git')], $this->root);
        $this->git(['init', '--initial-branch=main', $this->path('source')], $this->root);
        $this->git(['config', 'user.name', 'Test'], $this->path('source'));
        $this->git(['config', 'user.email', 'test@example.test'], $this->path('source'));
        $this->commitSource('first');
        $this->git(['remote', 'add', 'origin', $this->path('origin.git')], $this->path('source'));
        $this->git(['push', 'origin', 'main'], $this->path('source'));
        $this->git(['clone', '--branch=main', $this->path('origin.git'), $this->path('client')], $this->root);
        $this->git(['tag', 'local-only'], $this->path('client'));
        $this->commitSource('second');
        $this->git(['tag', '-a', 'channel', '-m', 'annotated tag'], $this->path('source'));
        $this->git(['push', 'origin', 'main', 'refs/tags/channel'], $this->path('source'));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        if (isset($this->root)) {
            (new Filesystem())->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function testAppliedAnnotatedTagStillAppearsInRunMetadata(): void
    {
        $driver = new GitDriver($this->shell, []);
        $step = new GitUpdateStep($driver, null, $this->shell);
        $context = ['options' => ['update_type' => 'git_tag', 'target_tag' => 'channel']];
        $step->handle($context);

        self::assertSame('channel', $context['git_tag_after']);
        self::assertSame('channel', GitDriver::fetchedTagAtHead($this->shell, $this->path('client')));
        self::assertSame('local-only', $this->git(['tag', '--list'], $this->path('client')));
        self::assertFalse($driver->statusUpdates()['has_update_by_tag']);
    }

    public function testTagFetchDoesNotApplyConfiguredNativeTagMappingsOrPruning(): void
    {
        $before = $this->git(['show-ref', '--tags'], $this->path('client'));
        $this->git(['config', 'fetch.prune', 'true'], $this->path('client'));
        $this->git(['config', 'fetch.pruneTags', 'true'], $this->path('client'));
        $this->git(['config', 'remote.origin.tagOpt', '--tags'], $this->path('client'));
        $this->git(['config', '--add', 'remote.origin.fetch', '+refs/tags/*:refs/tags/*'], $this->path('client'));

        (new GitDriver($this->shell, []))->update();

        self::assertSame($before, $this->git(['show-ref', '--tags'], $this->path('client')));
        self::assertSame($this->git(['rev-parse', 'HEAD'], $this->path('source')), $this->git(['rev-parse', 'HEAD'], $this->path('client')));
    }

    public function testPreviousDetachedCommitSurvivesMovedTagGcAndOfflineRollback(): void
    {
        $driver = new GitDriver($this->shell, []);
        $previous = $driver->update();
        // Nova geração do canal sem ancestralidade: o commit anterior não pode
        // depender do histórico remoto nem de reflog para continuar recuperável.
        $this->git(['checkout', '--orphan', 'replacement'], $this->path('source'));
        $this->commitSource('third');
        $this->git(['tag', '-f', 'channel'], $this->path('source'));
        $this->git(['push', 'origin', '+refs/tags/channel:refs/tags/channel'], $this->path('source'));
        $current = $driver->update();
        self::assertNotSame($previous, $current);
        $this->git(['reflog', 'expire', '--expire=now', '--all'], $this->path('client'));
        $this->git(['gc', '--prune=now'], $this->path('client'));
        $this->git(['remote', 'set-url', 'origin', $this->path('unavailable.git')], $this->path('client'));

        $driver->rollback($previous);

        self::assertSame($previous, $this->git(['rev-parse', 'HEAD'], $this->path('client')));
        self::assertSame("second\n", file_get_contents($this->path('client') . '/README.md'));
    }

    private function commitSource(string $text): void
    {
        file_put_contents($this->path('source') . '/README.md', $text . "\n");
        $this->git(['add', 'README.md'], $this->path('source'));
        $this->git(['commit', '-m', $text], $this->path('source'));
    }

    private function path(string $name): string { return $this->root . '/' . $name; }

    private function git(array $args, string $cwd): string
    {
        return trim((string) $this->shell->runOrFail(array_merge(['git'], $args), $cwd)['stdout']);
    }
}
