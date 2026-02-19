<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class MaintenanceOffStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly LockInterface $lock)
    {
    }

    public function name(): string { return 'maintenance_off'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $this->shellRunner->runOrFail(['php', 'artisan', 'up']);
        $this->lock->release();
        $context['maintenance'] = false;
    }

    public function rollback(array &$context): void
    {
        $this->lock->release();
        $this->shellRunner->run(['php', 'artisan', 'up']);
    }
}
