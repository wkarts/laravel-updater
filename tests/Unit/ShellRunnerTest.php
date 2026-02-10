<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\ShellRunner;
use PHPUnit\Framework\TestCase;

class ShellRunnerTest extends TestCase
{
    public function testExecutaComandoComSucesso(): void
    {
        $runner = new ShellRunner();
        $result = $runner->runOrFail(['php', '-r', 'echo "ok";']);

        $this->assertSame('ok', $result['stdout']);
        $this->assertSame(0, $result['exit_code']);
    }
}
