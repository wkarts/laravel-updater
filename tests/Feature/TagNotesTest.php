<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Support\ReleaseNotesResolver;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

final class TagNotesTest extends TestCase
{
    public function testExactGithubTagAndPrereleaseMetadataAreCached(): void
    {
        config(['cache.default' => 'array']);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/acme/project/releases/tags/v2.0.0-rc.1' => Http::response([
            'tag_name' => 'v2.0.0-rc.1', 'name' => 'Release Candidate', 'body' => 'Only this exact tag.',
            'published_at' => '2026-10-15T12:00:00Z', 'prerelease' => true,
        ])]);
        $resolver = new ReleaseNotesResolver();
        $metadata = $resolver->metadata('https://github.com/acme/project.git', 'v2.0.0-rc.1');
        self::assertSame('available', $metadata['availability']);
        self::assertSame('v2.0.0-rc.1', $metadata['tag']);
        self::assertTrue($metadata['prerelease']);
        self::assertSame('Only this exact tag.', $metadata['notes']);
        self::assertSame($metadata, $resolver->metadata('https://github.com/acme/project.git', 'v2.0.0-rc.1'));
        Http::assertSentCount(1);
    }

    public function testMismatchedAndMissingReleaseNeverFallBackToLatest(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v9.0.0', 'body' => 'Wrong version!'])]);
        $metadata = (new ReleaseNotesResolver())->metadata('git@github.com:acme/project.git', 'v1.2.3');
        self::assertSame('unavailable', $metadata['availability']);
        self::assertNull($metadata['notes']);
        self::assertSame('v1.2.3', $metadata['tag']);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/latest'));
    }

    public function testGitlabSupportsNestedProjectExactTag(): void
    {
        Http::fake(['gitlab.com/api/v4/projects/acme%2Fteam%2Fproject/releases/v1.0-beta' => Http::response([
            'tag_name' => 'v1.0-beta', 'description' => 'GitLab beta notes.', 'released_at' => '2026-10-10T10:00:00Z',
        ])]);
        $metadata = (new ReleaseNotesResolver())->metadata('https://gitlab.com/acme/team/project.git', 'v1.0-beta');
        self::assertSame('available', $metadata['availability']);
        self::assertSame('GitLab beta notes.', $metadata['notes']);
        self::assertTrue($metadata['prerelease']);
    }

    public function testGenericGitAndForgedProviderHostsNeverMakeRequests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $resolver = new ReleaseNotesResolver();
        self::assertNull($resolver->resolve('https://example.com/github.com/acme/project', 'v1'));
        $generic = $resolver->metadata('https://internal.invalid/acme/project.git', 'v1');
        self::assertSame('unavailable', $generic['availability']);
        self::assertNull($generic['notes']);
        self::assertNull($generic['url']);
        Http::assertNothingSent();
    }

    public function testRepositoryEmbeddedPasswordCannotReachReleaseLink(): void
    {
        self::assertSame('https://github.com/acme/project/releases/tag/v1', (new ReleaseNotesResolver())->resolve('https://user:private-token@github.com/acme/project.git', 'v1'));
    }

    public function testSelectedSourceWinsOverActiveSourceAndCacheIsIsolatedByRepository(): void
    {
        $state = new StateStore(':memory:');
        $state->ensureSchema();
        $manager = new ManagerStore($state);
        $this->app->instance(StateStore::class, $state);
        $this->app->instance(ManagerStore::class, $manager);
        $manager->createOrUpdateSource(['name' => 'active', 'type' => 'github', 'repo_url' => 'https://github.com/acme/active.git', 'active' => 1]);
        $activeId = $manager->activeSource()['id'];
        $manager->createOrUpdateSource(['name' => 'selected', 'type' => 'github', 'repo_url' => 'https://github.com/acme/selected.git', 'active' => 0]);
        $selectedId = $state->pdo()->lastInsertId();
        Http::fake([
            'api.github.com/repos/acme/active/releases/tags/v1' => Http::response(['tag_name' => 'v1', 'body' => 'Active notes']),
            'api.github.com/repos/acme/selected/releases/tags/v1' => Http::response(['tag_name' => 'v1', 'body' => 'Selected notes']),
        ]);
        Route::get('/notes', [UpdaterUiController::class, 'releaseNotes']);
        $this->getJson('/notes?tag=v1')->assertOk()->assertJsonPath('notes', 'Active notes');
        $this->getJson('/notes?tag=v1&source_id=' . $selectedId)->assertOk()->assertJsonPath('notes', 'Selected notes');
        $this->getJson('/notes?tag=v1&source_id=' . $activeId)->assertOk()->assertJsonPath('notes', 'Active notes');
        $this->getJson('/notes?tag=v1&source_id=999')->assertNotFound();
        $this->getJson('/notes?tag=v1&source_id=invalid')->assertStatus(422);
        Http::assertSentCount(2);
    }
}
