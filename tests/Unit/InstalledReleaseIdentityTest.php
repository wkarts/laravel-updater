<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\InstalledReleaseIdentity;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class InstalledReleaseIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/updater-installed-identity-' . bin2hex(random_bytes(7));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function testAnnotatedPrivateTargetExposesSemanticBetaAndBaseWithoutLocalTags(): void
    {
        $sha = $this->repository();
        $this->annotate('ui-continuous', $this->metadata($sha));
        $this->git(['update-ref', 'refs/laravel-updater/targets/ui-continuous', 'refs/tags/ui-continuous']);
        $this->git(['tag', '-d', 'ui-continuous']);
        $identity = (new InstalledReleaseIdentity($this->root))->resolve();
        self::assertSame('5.0.236-beta.53', $identity['version']);
        self::assertSame('ui-continuous', $identity['tag']);
        self::assertSame('v5.0.235', $identity['base_stable_tag']);
        self::assertSame($sha, $identity['git_commit_sha']);
        self::assertSame('53', $identity['build_number']);
        self::assertSame('', $this->git(['tag', '--list']));
        self::assertArrayNotHasKey('token', $identity);
    }

    public function testMovingTechnicalTagAloneNeverInventsTheReleaseVersion(): void
    {
        $sha = $this->repository();
        $this->git(['update-ref', 'refs/laravel-updater/targets/ui-continuous', $sha]);
        self::assertNull((new InstalledReleaseIdentity($this->root))->resolve());
    }

    public function testExactLightweightSemanticTagsAreSupported(): void
    {
        $sha = $this->repository();
        $this->git(['tag', 'v3.4.5-rc.2+build.8']);
        $identity = (new InstalledReleaseIdentity($this->root))->resolve();
        self::assertSame('3.4.5-rc.2+build.8', $identity['version']);
        self::assertSame($sha, $identity['git_commit_sha']);
        self::assertSame('prerelease', $identity['release_channel']);
        self::assertNull($identity['base_stable_tag']);
    }

    public function testUnrelatedAndAncestorTagsAreNotTheInstalledVersion(): void
    {
        $this->repository();
        $this->git(['tag', 'v999.0.0']);
        $this->commit('second');
        self::assertNull((new InstalledReleaseIdentity($this->root))->resolve());
    }

    public function testMetadataCannotOverrideTheNameOfAnExactSemanticTag(): void
    {
        $sha = $this->repository();
        $this->annotate('v2.0.0', $this->metadata($sha));
        self::assertSame('2.0.0', (new InstalledReleaseIdentity($this->root))->resolve()['version']);
    }

    public function testMetadataMustMatchBothHeadAndTheAnnotatedTarget(): void
    {
        $old = $this->repository();
        $head = $this->commit('second');
        $this->annotate('ui-continuous', $this->metadata($head), $old);
        self::assertNull((new InstalledReleaseIdentity($this->root))->resolve());
        $this->annotate('ui-continuous', $this->metadata($old));
        self::assertNull((new InstalledReleaseIdentity($this->root))->resolve());
    }

    public function testSameReaderObservesExternalCheckoutAndRollbackWithoutCachedIdentity(): void
    {
        $old = $this->repository();
        $this->annotate('ui-continuous', $this->metadata($old));
        $reader = new InstalledReleaseIdentity($this->root);
        self::assertSame($old, $reader->resolve()['git_commit_sha']);
        $this->commit('new revision');
        self::assertNull($reader->resolve());
        $this->git(['checkout', '--detach', $old]);
        self::assertSame($old, $reader->resolve()['git_commit_sha']);
    }

    public function testGenericPrereleasesDoNotRequireTheNextPatchOfTheStableBase(): void
    {
        $data = $this->metadata(str_repeat('b', 40));
        $data['version'] = 'v8.0.0-preview.1';
        $data['base_stable_tag'] = 'v5.0.235';
        $data['release_channel'] = 'preview';
        $data['build_number'] = 'ci-2026.10';
        file_put_contents($this->root . '/build-info.json', json_encode($data));
        $identity = (new InstalledReleaseIdentity($this->root))->resolve();
        self::assertSame('8.0.0-preview.1', $identity['version']);
        self::assertSame('v5.0.235', $identity['base_stable_tag']);
        self::assertSame('ci-2026.10', $identity['build_number']);
    }

    public function testZipMetadataWorksWithoutGitAndRejectsSecretsFromOutput(): void
    {
        $data = $this->metadata(str_repeat('b', 40));
        unset($data['schema_version']);
        $data['token'] = 'must-never-be-exposed';
        file_put_contents($this->root . '/ui-build-info.json', json_encode($data));
        $identity = (new InstalledReleaseIdentity($this->root))->resolve();
        self::assertSame('build-info', $identity['source']);
        self::assertSame('5.0.236-beta.53', $identity['version']);
        self::assertStringNotContainsString('must-never-be-exposed', json_encode($identity));
    }

    public function testStaleBuildInfoCannotMaskAnExactLocalTag(): void
    {
        $this->repository();
        $this->git(['tag', 'v5.0.235']);
        file_put_contents($this->root . '/ui-build-info.json', json_encode($this->metadata(str_repeat('b', 40))));
        self::assertSame('5.0.235', (new InstalledReleaseIdentity($this->root))->resolve()['version']);
    }

    public function testInvalidOrOversizedMetadataIsIgnored(): void
    {
        $data = $this->metadata(str_repeat('b', 40));
        foreach ([
            ['schema_version' => 2], ['git_commit_sha' => 'abc1234'], ['version' => '../v5.0.235'],
            ['version' => '5.0.236-beta.01'], ['base_stable_tag' => 'v5.0.235-rc.1'],
            ['release_channel' => 'stable'], ['build_number' => ['53']], ['tag' => '../unsafe'],
        ] as $invalid) {
            file_put_contents($this->root . '/release-info.json', json_encode(array_replace($data, $invalid)));
            self::assertNull((new InstalledReleaseIdentity($this->root))->resolve(), json_encode($invalid));
        }
        file_put_contents($this->root . '/release-info.json', '{broken');
        self::assertNull((new InstalledReleaseIdentity($this->root))->resolve());
        file_put_contents($this->root . '/release-info.json', str_repeat('x', 20001));
        self::assertNull((new InstalledReleaseIdentity($this->root))->resolve());
    }

    private function repository(): string
    {
        $this->git(['init', '--initial-branch=main']);
        $this->git(['config', 'user.name', 'Identity Test']);
        $this->git(['config', 'user.email', 'identity@example.test']);
        return $this->commit('initial');
    }

    private function commit(string $message): string
    {
        file_put_contents($this->root . '/code.txt', $message);
        $this->git(['add', 'code.txt']);
        $this->git(['commit', '-m', $message]);
        return $this->git(['rev-parse', 'HEAD']);
    }

    private function metadata(string $sha): array
    {
        return ['schema_version' => 1, 'version' => 'v5.0.236-beta.53', 'base_stable_tag' => 'v5.0.235',
            'git_commit_sha' => $sha, 'release_channel' => 'beta-continuous', 'build_number' => 53];
    }

    private function annotate(string $tag, array $metadata, ?string $target = null): void
    {
        $this->git(['tag', '-a', '-f', $tag, '-m', json_encode($metadata), $target ?? 'HEAD']);
    }

    private function git(array $arguments): string
    {
        $process = new Process(array_merge(['git'], $arguments), $this->root);
        $process->mustRun();
        return trim($process->getOutput());
    }
}
