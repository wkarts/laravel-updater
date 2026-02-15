<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class MigrateStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'migrate'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $options = $context['options'] ?? [];
        $command = ['php', 'artisan', 'updater:migrate', '--force'];

        if (($context['run_id'] ?? null) !== null) {
            $command[] = '--run-id=' . $context['run_id'];
        }

        if ((bool) ($options['strict_migrate'] ?? false)) {
            $command[] = '--mode=strict';
        }

        if ((bool) ($options['dry_run'] ?? false)) {
            $command[] = '--dry-run';
        }

        $command[] = '--retry-locks=' . (int) config('updater.migrate.retry_locks', 2);
        $command[] = '--retry-sleep-base=' . (int) config('updater.migrate.retry_sleep_base', 3);

        $this->shellRunner->runOrFail($command);
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['php', 'artisan', 'migrate:rollback', '--force']);
    }
}
