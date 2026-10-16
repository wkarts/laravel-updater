<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateMaintenanceState;

class MaintenanceOffStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly LockInterface $lock, private readonly ?StateStore $store = null)
    {
    }

    public function name(): string { return 'maintenance_off'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        try {
            UpdateMaintenanceState::release($context, $this->shellRunner, true, $this->store);
        } finally {
            // O lock independe da opção de manutenção.
            $this->lock->release();
        }
    }

    public function rollback(array &$context): void
    {
        $this->handle($context);
    }
}
