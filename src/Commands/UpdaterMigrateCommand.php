<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Migration\IdempotentMigrationService;
use Argws\LaravelUpdater\Migration\MigrationRunReporter;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Console\Command;
use Throwable;

class UpdaterMigrateCommand extends Command
{
    protected $signature = 'updater:migrate
        {--database= : Conexão de banco alvo}
        {--path= : Caminho customizado de migrations (arquivo ou diretório)}
        {--mode= : Modo de execução (tolerant|strict)}
        {--strict : Atalho para --mode=strict}
        {--dry-run : Não executa SQL, apenas simula}
        {--retry-locks= : Máximo de retries para lock/deadlock}
        {--retry-sleep-base= : Backoff base em segundos}
        {--run-id= : Vincula logs ao run_id do updater}
        {--force : Compatibilidade com chamadas não interativas}';

    protected $description = 'Executa migrações de forma idempotente, uma por vez, com reconciliação de drift e retries.';

    public function handle(IdempotentMigrationService $service, StateStore $store): int
    {
        $runId = $this->option('run-id');
        $runId = is_numeric($runId) ? (int) $runId : null;

        $mode = (string) ($this->option('mode') ?: config('updater.migrate.mode', 'tolerant'));
        if ((bool) config('updater.migrate.strict_mode', false)) {
            $mode = 'strict';
        }
        if ((bool) $this->option('strict')) {
            $mode = 'strict';
        }

        $logFile = (string) config('updater.migrate.report_path', storage_path('logs/updater-migrate.log'));
        if (str_contains($logFile, '{timestamp}')) {
            $logFile = str_replace('{timestamp}', date('Ymd-His'), $logFile);
        }

        $reporter = new MigrationRunReporter(
            $store,
            $logFile,
            $runId,
            (string) config('updater.migrate.log_channel', 'stack')
        );

        $options = [
            'idempotent' => (bool) config('updater.migrate.idempotent', true),
            'database' => $this->option('database') ?: config('database.default'),
            'path' => $this->option('path') ?: null,
            'mode' => $mode,
            'strict' => $mode === 'strict',
            'dry_run' => (bool) ($this->option('dry-run') ?: config('updater.migrate.dry_run', false)),
            'retry_locks' => $this->option('retry-locks') ?? config('updater.migrate.retry_locks', config('updater.migrate.max_retries', 2)),
            'retry_sleep_base' => $this->option('retry-sleep-base') ?? config('updater.migrate.retry_sleep_base', max(1, (int) round(((int) config('updater.migrate.backoff_ms', 3000)) / 1000))),
            'reconcile_already_exists' => (bool) config('updater.migrate.reconcile_already_exists', true),
        ];

        try {
            $stats = $service->run($options, $reporter);
            $summary = $reporter->summary($stats);
            $this->info('updater:migrate finalizado.');
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $reporter->log('error', 'updater:migrate falhou.', ['error' => $throwable->getMessage()]);
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
