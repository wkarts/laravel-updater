<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

class MaintenanceOffStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly LockInterface $lock,
        private readonly StateStore $stateStore,
    )
    {
    }

    public function name(): string { return 'maintenance_off'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        // Disable package soft maintenance.
        $this->stateStore->set('soft_maintenance', ['enabled' => false]);

        // Best-effort: if the host app was put into native maintenance manually, lift it.
        // We ignore failures here because this package does not rely on `artisan down/up`.
        try {
            $this->shellRunner->runOrFail(['php', 'artisan', 'up']);
        } catch (\Throwable $e) {
            // noop
        }
        $this->lock->release();
        $context['maintenance'] = false;
    }

    public function rollback(array &$context): void
    {
        $this->lock->release();
        $this->stateStore->set('soft_maintenance', ['enabled' => false]);
        $this->shellRunner->run(['php', 'artisan', 'up']);
    }
}
