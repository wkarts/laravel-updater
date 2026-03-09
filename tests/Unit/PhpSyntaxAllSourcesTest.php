<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhpSyntaxAllSourcesTest extends TestCase
{
    public function testAllSourceFilesHaveValidPhpSyntax(): void
    {
        $root = dirname(__DIR__, 2);
        $command = sprintf("cd %s && find src -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1", escapeshellarg($root));

        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
