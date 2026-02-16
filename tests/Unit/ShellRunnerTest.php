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

    public function testInjetaEncryptionKeyDoDotEnvQuandoAusenteNoAmbiente(): void
    {
        $runner = new ShellRunner();

        $tempDir = sys_get_temp_dir() . '/updater-shellrunner-' . uniqid('', true);
        @mkdir($tempDir, 0775, true);
        file_put_contents($tempDir . '/.env', "ENCRYPTION_KEY=base64:test-key\n");

        $result = $runner->runOrFail(['php', '-r', 'echo getenv("ENCRYPTION_KEY") ?: "";'], $tempDir, ['PATH' => (string) getenv('PATH')]);

        $this->assertSame('base64:test-key', $result['stdout']);
    }
}
