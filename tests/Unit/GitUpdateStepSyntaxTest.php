<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GitUpdateStepSyntaxTest extends TestCase
{
    public function testGitUpdateStepFileHasValidPhpSyntax(): void
    {
        $file = dirname(__DIR__, 2) . '/src/Pipeline/Steps/GitUpdateStep.php';
        $this->assertFileExists($file);

        $command = sprintf('php -l %s 2>&1', escapeshellarg($file));
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
