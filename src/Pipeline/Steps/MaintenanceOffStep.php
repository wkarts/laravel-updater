<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

/**
 * Desativa "soft maintenance" e tenta (best-effort) executar artisan up.
 */
class MaintenanceOffStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly StateStore $stateStore,
    ) {
    }

    public function name(): string { return 'maintenance_off'; }

    public function shouldRun(array $context): bool
    {
        return (bool) ($context['maintenance'] ?? false);
    }

    public function handle(array &$context): void
    {
        $this->stateStore->set('soft_maintenance', [
            'enabled' => false,
        ]);

        try {
            $this->shellRunner->run('php artisan up');
        } catch (\Throwable) {
            // ignore
        }

        $context['maintenance'] = false;
    }

    public function rollback(array &$context): void
    {
        // nada
    }
}
