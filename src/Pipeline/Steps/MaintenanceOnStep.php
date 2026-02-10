<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class MaintenanceOnStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'maintenance_on'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $this->shellRunner->runOrFail(['php', 'artisan', 'down', '--render=errors::503']);
        $context['maintenance'] = true;
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['php', 'artisan', 'up']);
    }
}
