<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Support\Facades\Cache;

class MaintenanceOffStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly LockInterface $lock)
    {
    }

    public function name(): string { return 'maintenance_off'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        // Desativa manutenção soft sempre (mesmo que o modo nativo não tenha sido usado)
        Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);

        // Best-effort: se o app estiver em maintenance nativo, sobe.
        $this->shellRunner->run(['php', 'artisan', 'up']);
        $this->lock->release();
        $context['maintenance'] = false;
    }

    public function rollback(array &$context): void
    {
        Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);
        $this->lock->release();
        $this->shellRunner->run(['php', 'artisan', 'up']);
    }
}
