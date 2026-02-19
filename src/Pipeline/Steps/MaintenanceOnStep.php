<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

/**
 * Ativa "soft maintenance" (não usa artisan down) para nunca bloquear a UI do updater.
 */
class MaintenanceOnStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly StateStore $stateStore,
    ) {
    }

    public function name(): string { return 'maintenance_on'; }

    public function shouldRun(array $context): bool
    {
        return (bool) ($context['use_maintenance'] ?? true);
    }

    public function handle(array &$context): void
    {
        $view = (string) config('updater.maintenance.render_view', (string) env('UPDATER_MAINTENANCE_VIEW', 'laravel-updater::maintenance'));

        $this->stateStore->set('soft_maintenance', [
            'enabled' => true,
            'title' => (string) config('updater.maintenance.title', 'Atualização em andamento'),
            'message' => (string) config('updater.maintenance.message', 'Estamos atualizando o sistema. Volte em alguns minutos.'),
            'view' => $view,
        ]);

        // Best-effort: alguns projetos ainda preferem criar o arquivo down do Laravel (ex: load balancer/health checks).
        // Não falha se não der certo.
        try {
            if ((bool) config('updater.maintenance.try_native', false)) {
                $this->shellRunner->run('php artisan down');
            }
        } catch (\Throwable) {
            // ignore
        }

        $context['maintenance'] = true;
    }

    public function rollback(array &$context): void
    {
        // nada
    }
}
