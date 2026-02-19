<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\GitMaintenance;

class GitMaintenanceStep implements PipelineStepInterface
{
    public function __construct(private readonly GitMaintenance $maintenance, private readonly string $reason)
    {
    }

    public function name(): string
    {
        return 'git_maintenance_' . $this->reason;
    }

    /**
     * Manutenção do .git é "best-effort" e não deve derrubar a pipeline.
     */
    public function shouldRun(array $context): bool
    {
        return (bool) config('updater.git_maintenance.enabled', true);
    }

    public function handle(array &$context): void
    {
        // Mantém compatibilidade com implementações antigas que usavam run()
        $context = $this->run($context);
    }

    public function rollback(array &$context): void
    {
        // No-op: manutenção do repositório não tem rollback.
    }

    /**
     * Compatibilidade retro: algumas versões antigas chamavam run() e esperavam retorno.
     */
    public function run(array $context): array
    {
        try {
            $report = $this->maintenance->maintain($this->reason);
            $context['git_maintenance'][$this->reason] = $report;
        } catch (\Throwable $e) {
            // Não falha a pipeline por manutenção; registra apenas.
            $context['git_maintenance'][$this->reason] = [
                'ok' => false,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
            ];
        }

        return $context;
    }
}
