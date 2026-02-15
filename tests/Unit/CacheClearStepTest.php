<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Exceptions\UpdaterException;
use Argws\LaravelUpdater\Pipeline\Steps\CacheClearStep;
use Argws\LaravelUpdater\Support\ShellRunner;
use PHPUnit\Framework\TestCase;

class CacheClearStepTest extends TestCase
{
    public function testIgnoraFalhaDeRouteCachePorNomeDuplicadoQuandoConfigurado(): void
    {
        $runner = new class extends ShellRunner {
            public array $commands = [];

            public function runOrFail(array $command, ?string $cwd = null, array $env = []): array
            {
                $this->commands[] = implode(' ', $command);

                if (($command[2] ?? '') === 'route:cache') {
                    throw new UpdaterException('Comando falhou (1): php artisan route:cache LogicException Unable to prepare route [x]. Another route has already been assigned name [x].');
                }

                return ['stdout' => '', 'stderr' => '', 'exit_code' => 0];
            }

            public function run(array $command, ?string $cwd = null, array $env = []): array
            {
                $this->commands[] = implode(' ', $command);
                return ['stdout' => '', 'stderr' => '', 'exit_code' => 0];
            }
        };

        $step = new CacheClearStep($runner);
        $context = [];
        $step->handle($context);

        $this->assertNotEmpty($context['cache_clear_warning'] ?? []);
        $this->assertContains('php artisan route:clear', $runner->commands);
    }
}
