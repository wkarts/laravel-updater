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
        {--strict : Não reconcilia drift de already exists}
        {--dry-run : Não executa SQL, apenas simula}
        {--max-retries= : Máximo de retries para lock/deadlock}
        {--backoff-ms= : Backoff base em milissegundos}
        {--run-id= : Vincula logs ao run_id do updater}
        {--force : Compatibilidade com chamadas não interativas}';

    protected $description = 'Executa migrações de forma idempotente, uma por vez, com reconciliação de drift e retries.';

    public function handle(IdempotentMigrationService $service, StateStore $store): int
    {
        $runId = $this->option('run-id');
        $runId = is_numeric($runId) ? (int) $runId : null;

        $logFile = (string) config('updater.migrate.report_path', storage_path('logs/updater-migrate.log'));
        if (str_contains($logFile, '{timestamp}')) {
            $logFile = str_replace('{timestamp}', date('Ymd-His'), $logFile);
        }

        $reporter = new MigrationRunReporter($store, $logFile, $runId);

        $options = [
            'database' => $this->option('database') ?: config('database.default'),
            'path' => $this->option('path') ?: null,
            'strict' => (bool) ($this->option('strict') ?: config('updater.migrate.strict_mode', false)),
            'dry_run' => (bool) ($this->option('dry-run') ?: config('updater.migrate.dry_run', false)),
            'max_retries' => $this->option('max-retries') ?? config('updater.migrate.max_retries', 3),
            'backoff_ms' => $this->option('backoff-ms') ?? config('updater.migrate.backoff_ms', 500),
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
