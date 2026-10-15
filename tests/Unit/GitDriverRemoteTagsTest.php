<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class GitDriverRemoteTagsTest extends TestCase
{
    private string $directory;

    private ShellRunner $shell;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/updater-remote-tags-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
        $this->shell = new ShellRunner();

        $container = new Container();
        $container->instance('config', new Repository([
            'updater' => ['git' => [
                'path' => $this->consumerPath(),
                'remote' => 'origin',
                'branch' => 'main',
            ]],
        ]));
        Container::setInstance($container);

        $this->git(['init', '--bare', $this->originPath()], $this->directory);
        $this->git(['init', '--initial-branch=main', $this->sourcePath()], $this->directory);
        $this->git(['config', 'user.name', 'Updater Test'], $this->sourcePath());
        $this->git(['config', 'user.email', 'updater@example.test'], $this->sourcePath());

        file_put_contents($this->sourcePath() . '/README.md', "first\n");
        $this->git(['add', 'README.md'], $this->sourcePath());
        $this->git(['commit', '-m', 'initial'], $this->sourcePath());
        $this->git(['tag', 'v1.0.0'], $this->sourcePath());
        $this->git(['remote', 'add', 'origin', $this->originPath()], $this->sourcePath());
        $this->git(['push', '-u', 'origin', 'main', 'refs/tags/v1.0.0'], $this->sourcePath());
        $this->git(['clone', '--branch', 'main', $this->originPath(), $this->consumerPath()], $this->directory);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        if (isset($this->directory) && is_dir($this->directory)) {
            (new Filesystem())->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function testStatusAndTagsContinueWorkingWhenLocalTagWouldClobberRemoteTag(): void
    {
        // A mesma tag aponta para commits diferentes no consumidor e na origem.
        $this->git(['tag', 'ui-continuous'], $this->consumerPath());
        $localTagBefore = $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath());

        file_put_contents($this->sourcePath() . '/README.md', "second\n");
        $this->git(['add', 'README.md'], $this->sourcePath());
        $this->git(['commit', '-m', 'second'], $this->sourcePath());
        $this->git(['tag', 'ui-continuous'], $this->sourcePath());
        $this->git(['tag', 'v1.0.1'], $this->sourcePath());
        $this->git(['push', 'origin', 'main', 'refs/tags/ui-continuous', 'refs/tags/v1.0.1'], $this->sourcePath());

        $driver = $this->driver();
        $status = $driver->statusUpdates();

        self::assertSame(1, $status['behind_by_commits']);
        self::assertSame(0, $status['ahead_by_commits']);
        self::assertTrue($status['has_updates']);
        self::assertSame('v1.0.1', $status['latest_tag']);
        self::assertTrue($status['has_update_by_tag']);
        self::assertSame(
            ['v1.0.1', 'v1.0.0', 'ui-continuous'],
            $driver->listTags(10)
        );
        self::assertSame(
            $localTagBefore,
            $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()),
            'Consulta de status não deve reescrever a tag local.'
        );
    }

    public function testAlternativeBranchAndPreReleaseTagRemainSupported(): void
    {
        $this->git(['checkout', '-b', 'preview'], $this->sourcePath());
        file_put_contents($this->sourcePath() . '/preview.txt', "preview\n");
        $this->git(['add', 'preview.txt'], $this->sourcePath());
        $this->git(['commit', '-m', 'preview'], $this->sourcePath());
        $this->git(['tag', 'v1.1.0-rc.1'], $this->sourcePath());
        $this->git(['push', 'origin', 'preview', 'refs/tags/v1.1.0-rc.1'], $this->sourcePath());

        app('config')->set('updater.git.branch', 'preview');

        $status = $this->driver()->statusUpdates();

        self::assertSame(1, $status['behind_by_commits']);
        self::assertSame('v1.1.0-rc.1', $status['latest_tag']);
        self::assertSame(['v1.1.0-rc.1'], $this->driver()->listTags(1));
    }

    private function driver(): GitDriver
    {
        return new GitDriver($this->shell, [
            'path' => $this->consumerPath(),
            'remote' => 'origin',
            'branch' => 'main',
        ]);
    }

    /** @param array<int,string> $arguments */
    private function git(array $arguments, string $directory): string
    {
        $result = $this->shell->runOrFail(array_merge(['git'], $arguments), $directory);

        return trim((string) $result['stdout']);
    }

    private function originPath(): string
    {
        return $this->directory . '/origin.git';
    }

    private function sourcePath(): string
    {
        return $this->directory . '/source';
    }

    private function consumerPath(): string
    {
        return $this->directory . '/consumer';
    }
}
