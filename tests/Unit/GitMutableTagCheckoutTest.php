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

        $container = new Container();
        $container->instance('config', new Repository([
            'updater' => [
                'git' => [
                    'path' => $this->consumerPath(),
                    'remote' => 'origin',
                    'branch' => 'main',
                    'update_type' => 'git_tag',
                    'tag' => 'ui-continuous',
                    'tag_fetch_depth' => 1,
                    'auto_stash' => true,
                ],
                'git_maintenance' => ['enabled' => false],
            ],
        ]));
        Container::setInstance($container);

        $this->git(['init', '--bare', $this->originPath()], $this->directory);
        $this->git(['init', '--initial-branch=main', $this->sourcePath()], $this->directory);
        $this->git(['config', 'user.name', 'Updater Test'], $this->sourcePath());
        $this->git(['config', 'user.email', 'updater@example.test'], $this->sourcePath());
        file_put_contents($this->sourcePath() . '/README.md', "first\n");
        $this->git(['add', 'README.md'], $this->sourcePath());
        $this->git(['commit', '-m', 'first'], $this->sourcePath());
        $this->git(['remote', 'add', 'origin', $this->originPath()], $this->sourcePath());
        $this->git(['push', '-u', 'origin', 'main'], $this->sourcePath());
        $this->git(['clone', '--branch', 'main', $this->originPath(), $this->consumerPath()], $this->directory);

        // O consumidor guarda a tag antiga. O remoto recebe uma tag homônima,
        // agora em outro commit. Essa é a falha da execução #186.
        $this->git(['tag', 'ui-continuous'], $this->consumerPath());
        $this->commitSource('second');
        $this->git(['tag', '-a', 'ui-continuous', '-m', 'channel release'], $this->sourcePath());
        $this->git(['tag', 'v1.2.0-rc.1'], $this->sourcePath());
        $this->git(['push', 'origin', 'main', 'refs/tags/ui-continuous', 'refs/tags/v1.2.0-rc.1'], $this->sourcePath());
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
        $expectedCommit = $this->git(['rev-parse', 'HEAD'], $this->sourcePath());

        $result = $this->driver('ui-continuous')->update();

        self::assertSame($expectedCommit, $result);
        self::assertSame($expectedCommit, $this->git(['rev-parse', 'HEAD'], $this->consumerPath()));
        self::assertSame($oldTag, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
        self::assertSame($expectedCommit, $this->fetchedCommit('ui-continuous'));
    }

    public function testPipelineWithActiveSourceNoLongerFailsOnExistingTag(): void
    {
        $oldTag = $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath());
        $expectedCommit = $this->git(['rev-parse', 'HEAD'], $this->sourcePath());

        $manager = $this->getMockBuilder(ManagerStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['activeSource'])
            ->getMock();
        $manager->method('activeSource')->willReturn([
            'id' => 1,
            'name' => 'Fersoft WEB',
            'type' => 'git_merge',
            'branch' => 'main',
            'repo_url' => $this->originPath(),
            'auth_mode' => 'none',
        ]);

        $step = new GitUpdateStep($this->driver('ui-continuous'), $manager, $this->shell);
        $context = [
            'run_id' => 186,
            'options' => [
                'update_type' => 'git_tag',
                'target_tag' => 'ui-continuous',
                'target_branch' => '',
                'force' => false,
                'allow_dirty' => false,
            ],
        ];
        $step->handle($context);

        self::assertSame($expectedCommit, $context['revision_after']);
        self::assertSame($expectedCommit, $this->git(['rev-parse', 'HEAD'], $this->consumerPath()));
        self::assertSame($oldTag, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
        self::assertSame('ui-continuous', $context['git_update_log'][1] === 'tag solicitada: ui-continuous' ? 'ui-continuous' : '');
        self::assertSame($expectedCommit, $this->fetchedCommit('ui-continuous'));
    }

    public function testTagCanMoveMoreThanOnceAndUpdaterAlwaysUsesRemoteCommit(): void
    {
        $originalLocalTag = $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath());
        $first = $this->driver('ui-continuous')->update();
        $this->commitSource('third');
        // O canal é móvel e passa de uma tag anotada para uma tag leve.
        $this->git(['tag', '-f', 'ui-continuous'], $this->sourcePath());
        $this->git(['push', '--force', 'origin', 'main', 'refs/tags/ui-continuous'], $this->sourcePath());
        $expected = $this->git(['rev-parse', 'HEAD'], $this->sourcePath());

        $second = $this->driver('ui-continuous')->update();

        self::assertNotSame($first, $second);
        self::assertSame($expected, $second);
        self::assertSame($originalLocalTag, $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()));
        self::assertSame($expected, $this->fetchedCommit('ui-continuous'));
    }

    public function testPreReleaseTagRemainsSupported(): void
    {
        $expected = $this->git(['rev-parse', 'HEAD'], $this->sourcePath());

        $result = $this->driver('v1.2.0-rc.1')->update();

        self::assertSame($expected, $result);
        self::assertSame($expected, $this->fetchedCommit('v1.2.0-rc.1'));
        self::assertSame(
            $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath()),
            $this->git(['rev-parse', 'refs/tags/ui-continuous'], $this->consumerPath())
        );
    }

    public function testLegacyTagDestinationsAreNotUsedByAnyUpdatePath(): void
    {
        $root = dirname(__DIR__, 2);
        $step = file_get_contents($root . '/src/Pipeline/Steps/GitUpdateStep.php');
        $driver = file_get_contents($root . '/src/Drivers/GitDriver.php');

        self::assertIsString($step);
        self::assertIsString($driver);
        self::assertStringContainsString('GitDriver::remoteTagTargetRef($requestedTag)', $step);
        self::assertStringContainsString('GitDriver::remoteTagTargetRef($tag)', $step);
        self::assertStringContainsString("'+refs/tags/'", $step . $driver);
        self::assertStringNotContainsString("':refs/tags/' . \$requestedTag", $step);
        self::assertStringNotContainsString("':refs/tags/' . \$tag", $driver);
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

    private function driver(string $tag): GitDriver
    {
        app('config')->set('updater.git.tag', $tag);

        return new GitDriver($this->shell, [
            'path' => $this->consumerPath(),
            'remote' => 'origin',
            'branch' => 'main',
            'update_type' => 'git_tag',
            'tag' => $tag,
        ]);
    }

    /** @param array<int,string> $args */
    private function git(array $args, string $cwd): string
    {
        $result = $this->shell->runOrFail(array_merge(['git'], $args), $cwd);

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
