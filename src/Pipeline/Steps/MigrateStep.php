<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Migration\IdempotentMigrationService;
use Argws\LaravelUpdater\Migration\MigrationRunReporter;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Container\Container;

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

        try {
            $this->shellRunner->runOrFail($command);
        } catch (\Throwable $e) {
            $message = mb_strtolower($e->getMessage());
            if (!str_contains($message, 'there are no commands defined in the "updater" namespace')) {
                throw $e;
            }

            // Fallback resiliente: executa o mesmo fluxo idempotente em-process,
            // evitando regressão para `artisan migrate --force` puro.
            $container = Container::getInstance();
            if ($container === null) {
                throw $e;
            }

            $service = $container->make(IdempotentMigrationService::class);

            $runId = is_numeric($context['run_id'] ?? null) ? (int) $context['run_id'] : null;
            $logFile = (string) config('updater.migrate.report_path', storage_path('logs/updater-migrate.log'));
            if (str_contains($logFile, '{timestamp}')) {
                $logFile = str_replace('{timestamp}', date('Ymd-His'), $logFile);
            }

            $reporter = new MigrationRunReporter(
                $container->bound(StateStore::class) ? $container->make(StateStore::class) : null,
                $logFile,
                $runId,
                (string) config('updater.migrate.log_channel', 'stack')
            );

            $mode = (bool) ($options['strict_migrate'] ?? false) ? 'strict' : (string) config('updater.migrate.mode', 'tolerant');
            $service->run([
                'idempotent' => (bool) config('updater.migrate.idempotent', true),
                'database' => config('database.default'),
                'mode' => $mode,
                'strict' => $mode === 'strict',
                'dry_run' => (bool) ($options['dry_run'] ?? false),
                'retry_locks' => (int) config('updater.migrate.retry_locks', 2),
                'retry_sleep_base' => (int) config('updater.migrate.retry_sleep_base', 3),
                'reconcile_already_exists' => (bool) config('updater.migrate.reconcile_already_exists', true),
            ], $reporter);

            $context['migrate_fallback'] = 'in_process_idempotent_migrate';
        }
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['php', 'artisan', 'migrate:rollback', '--force']);
    }
}
