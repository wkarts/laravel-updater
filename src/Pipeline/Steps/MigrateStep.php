<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Migration\IdempotentMigrationService;
use Argws\LaravelUpdater\Migration\MigrationRunReporter;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;

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

        // Ordem resiliente:
        // 1) Artisan in-process (menos sensível a cache/manifest de comando)
        // 2) Serviço idempotente direto
        // 3) Subprocesso como último fallback
        if ($this->tryArtisanInProcess($context, $options)) {
            $context['migrate_strategy'] = 'artisan_in_process';
            return;
        }

        try {
            $this->runIdempotentService($context, $options);
            $context['migrate_strategy'] = 'idempotent_service';
            return;
        } catch (\Throwable $serviceError) {
            $subprocessCommand = $this->buildSubprocessCommand($context, $options);
            try {
                $this->shellRunner->runOrFail($subprocessCommand);
                $context['migrate_strategy'] = 'artisan_subprocess';
                $context['migrate_service_fallback_error'] = $serviceError->getMessage();
                return;
            } catch (\Throwable $subprocessError) {
                throw $serviceError;
            }
        }
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['php', 'artisan', 'migrate:rollback', '--force']);
    }

    /** @param array<string,mixed> $options */
    private function tryArtisanInProcess(array &$context, array $options): bool
    {
        try {
            $artisanParams = [
                '--force' => true,
                '--retry-locks' => (int) config('updater.migrate.retry_locks', 2),
                '--retry-sleep-base' => (int) config('updater.migrate.retry_sleep_base', 3),
            ];

            if (($context['run_id'] ?? null) !== null) {
                $artisanParams['--run-id'] = (int) $context['run_id'];
            }

            if ((bool) ($options['strict_migrate'] ?? false)) {
                $artisanParams['--mode'] = 'strict';
            }

            if ((bool) ($options['dry_run'] ?? false)) {
                $artisanParams['--dry-run'] = true;
            }

            Artisan::call('updater:migrate', $artisanParams);

            return true;
        } catch (\Throwable $e) {
            if ($this->isUpdaterMigrateCommandMissing($e)) {
                return false;
            }

            // Falhas reais de migrate em processo devem subir.
            throw $e;
        }
    }

    private function isUpdaterMigrateCommandMissing(\Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        if ($e instanceof CommandNotFoundException && str_contains($message, 'updater:migrate')) {
            return true;
        }

        return str_contains($message, 'there are no commands defined in the "updater" namespace')
            || str_contains($message, 'command "updater:migrate" is not defined')
            || str_contains($message, 'the command "updater:migrate" does not exist')
            || str_contains($message, 'comando "updater:migrate" não está definido')
            || str_contains($message, 'not enough arguments');
    }

    /** @param array<string,mixed> $options */
    private function runIdempotentService(array &$context, array $options): void
    {
        $container = Container::getInstance();
        if ($container === null) {
            throw new \RuntimeException('Container do Laravel indisponível para executar migração idempotente.');
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
    }

    /** @param array<string,mixed> $options @return array<int,string> */
    private function buildSubprocessCommand(array $context, array $options): array
    {
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

        return $command;
    }
}
