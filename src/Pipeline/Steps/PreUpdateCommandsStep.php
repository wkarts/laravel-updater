<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class PreUpdateCommandsStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'pre_update_commands'; }

    public function shouldRun(array $context): bool
    {
        return !empty($context['options']['pre_update_commands']);
    }

    public function handle(array &$context): void
    {
        $commands = array_values(array_filter((array) ($context['options']['pre_update_commands'] ?? [])));

        foreach ($commands as $command) {
            $line = trim((string) $command);
            if ($line === '') {
                continue;
            }

            $this->guardHostAppCommand($line);

            if ((bool) ($context['options']['dry_run'] ?? false)) {
                $context['dry_run_plan']['pre_update_commands'][] = $line;
                continue;
            }

            $this->shellRunner->runOrFail(['bash', '-lc', $line]);
            $context['pre_update_commands_log'][] = $line . ': executado';
        }
    }

    private function guardHostAppCommand(string $line): void
    {
        $normalized = strtolower(trim($line));

        // Evita erro recorrente da aplicação host executando namespace inválido.
        // O updater usa system:update:* (run/check/status/rollback), não "updater" genérico.
        if (preg_match('/\bphp\s+artisan\s+updater(\s|$)/', $normalized) === 1) {
            throw new \RuntimeException('Comando inválido no pre_update_commands: "' . $line . '". Use "php artisan system:update:run" (ou system:update:check/status/rollback).');
        }
    }

    public function rollback(array &$context): void
    {
    }
}
