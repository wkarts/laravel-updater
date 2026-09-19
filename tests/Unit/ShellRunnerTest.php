<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Exceptions\UpdaterException;
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


    public function testFallbackDeCwdInvalidoNaoQuebraExecucao(): void
    {
        $runner = new ShellRunner();
        $invalidCwd = sys_get_temp_dir() . '/updater-inexistente-' . uniqid('', true);

        $result = $runner->runOrFail(['php', '-r', 'echo "ok";'], $invalidCwd);

        $this->assertSame('ok', $result['stdout']);
        $this->assertSame(0, $result['exit_code']);
    }


    public function testRunOrFailWithTimeoutTambemUsaFallbackDeCwdInvalido(): void
    {
        $runner = new ShellRunner();
        $invalidCwd = sys_get_temp_dir() . '/updater-timeout-inexistente-' . uniqid('', true);

        $result = $runner->runOrFailWithTimeout(['php', '-r', 'echo "ok";'], $invalidCwd, [], 5);

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
    public function testRunDrenaStdoutEStderrSemDeadlock(): void
    {
        $runner = new ShellRunner();

        $code = <<<'PHP'
for ($i = 0; $i < 256; $i++) {
    fwrite(STDERR, str_repeat('E', 4096));
}
fwrite(STDOUT, 'ok');
PHP;

        $result = $runner->run(['php', '-r', $code]);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('ok', $result['stdout']);
        $this->assertGreaterThan(500000, strlen($result['stderr']));
    }

    public function testRunWithTimeoutInterrompeProcessoTravado(): void
    {
        $runner = new ShellRunner();

        $this->expectException(UpdaterException::class);
        $this->expectExceptionMessage('Comando excedeu timeout');

        $runner->runWithTimeout(['php', '-r', 'sleep(3);'], null, [], 1);
    }

    public function testGitRecebeAmbienteNaoInterativo(): void
    {
        $runner = new ShellRunner();

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('withCommandEnvironment');
        $method->setAccessible(true);

        $env = $method->invoke($runner, ['git', 'fetch', 'origin'], []);

        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
        $this->assertSame('Never', $env['GCM_INTERACTIVE']);
    }

}
