<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\ReleaseNotesResolver;
use PHPUnit\Framework\TestCase;

class ReleaseNotesResolverTest extends TestCase
{
    public function testResolveGithubHttpsTagUrl(): void
    {
        $resolver = new ReleaseNotesResolver();

        $url = $resolver->resolve('https://github.com/acme/project.git', 'v1.2.3');

        $this->assertSame('https://github.com/acme/project/releases/tag/v1.2.3', $url);
    }

    public function testResolveGithubSshTagUrl(): void
    {
        $resolver = new ReleaseNotesResolver();

        $url = $resolver->resolve('git@github.com:acme/project.git', 'v2.0.0');

        $this->assertSame('https://github.com/acme/project/releases/tag/v2.0.0', $url);
    }

    public function testResolveReturnsNullForUnknownProvider(): void
    {
        $resolver = new ReleaseNotesResolver();

        $this->assertNull($resolver->resolve('https://example.com/acme/project.git', 'v1.0.0'));
    }
}
