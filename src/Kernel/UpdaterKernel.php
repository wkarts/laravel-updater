<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Kernel;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Exceptions\RollbackException;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Pipeline\Steps\BackupDatabaseStep;
use Argws\LaravelUpdater\Pipeline\Steps\BuildAssetsStep;
use Argws\LaravelUpdater\Pipeline\Steps\CacheClearStep;
use Argws\LaravelUpdater\Pipeline\Steps\ComposerInstallStep;
use Argws\LaravelUpdater\Pipeline\Steps\GitUpdateStep;
use Argws\LaravelUpdater\Pipeline\Steps\HealthCheckStep;
use Argws\LaravelUpdater\Pipeline\Steps\LockStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOffStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOnStep;
use Argws\LaravelUpdater\Pipeline\Steps\MigrateStep;
use Argws\LaravelUpdater\Pipeline\Steps\SeedStep;
use Argws\LaravelUpdater\Pipeline\Steps\SnapshotCodeStep;
use Argws\LaravelUpdater\Pipeline\Steps\SqlPatchStep;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Argws\LaravelUpdater\Support\StateStore;
use Psr\Log\LoggerInterface;
use Throwable;

class UpdaterKernel
{
    public function __construct(
        private readonly EnvironmentDetector $environmentDetector,
        private readonly UpdatePipeline $pipeline,
        private readonly CodeDriverInterface $codeDriver,
        private readonly PreflightChecker $preflight,
        private readonly StateStore $store,
        private readonly LoggerInterface $logger,
        private readonly RunReportMailer $reportMailer
    ) {
    }

    public static function makePipeline(array $services): UpdatePipeline
    {
        return new UpdatePipeline([
            new LockStep($services['lock'], (int) config('updater.lock.timeout', 600)),
            new BackupDatabaseStep($services['backup'], $services['store'], (bool) config('updater.backup.enabled', true)),
            new SnapshotCodeStep($services['shell'], $services['files'], $services['store'], config('updater.snapshot'), $services['archive'] ?? null),
            new MaintenanceOnStep($services['shell']),
            new GitUpdateStep($services['code'], $services['manager_store'] ?? null, $services['shell']),
            new ComposerInstallStep($services['shell']),
            new MigrateStep($services['shell']),
            new SeedStep($services['shell'], $services['store']),
            new SqlPatchStep((string) config('updater.patches.path'), $services['store']),
            new BuildAssetsStep($services['shell'], (bool) config('updater.build_assets', false)),
            new CacheClearStep($services['shell']),
            new HealthCheckStep(config('updater.healthcheck')),
            new MaintenanceOffStep($services['shell'], $services['lock']),
        ], $services['logger'], $services['store']);
    }

    public function check(bool $allowDirty = false): array
    {
        $this->store->ensureSchema();
        $this->preflight->validate(['allow_dirty' => $allowDirty]);

        return [
            'enabled' => (bool) config('updater.enabled', true),
            'current_revision' => $this->codeDriver->currentRevision(),
            ...$this->codeDriver->statusUpdates(),
        ];
    }

    public function run(array $options = []): array
    {
        $this->environmentDetector->ensureCli((bool) ($options['allow_http'] ?? false));
        $this->store->ensureSchema();
        $isDryRun = (bool) ($options['dry_run'] ?? false);
        if (!$isDryRun) {
            $this->preflight->validate($options);
            // OBS: o bootstrap do repositório (git init/remote/fetch) é responsabilidade do CodeDriver.
            // Não bloqueie a pipeline aqui, pois instalações via ZIP/FTP não possuem .git inicialmente.
            // Se o auto-init estiver desativado, a falha ocorrerá no step git_update com mensagem mais específica.
        }

        $runId = $this->store->createRun($options);
        $context = [
            'run_id' => $runId,
            'options' => $options,
            'started_at' => now()->toIso8601String(),
            'idempotency_key' => hash('sha256', $runId . ':' . now()->timestamp),
        ];

        try {
            if ($isDryRun) {
                $status = $this->codeDriver->statusUpdates();
                $context['dry_run_plan'] = [
                    'versao_atual' => $this->codeDriver->currentRevision(),
                    'versao_alvo' => $status['remote'] ?? null,
                    'diff_commits' => $status['behind_by_commits'] ?? 0,
                    'steps' => ['lock','backup_database','snapshot_code','maintenance_on','git_update','composer_install','migrate','seed','sql_patch','build_assets','cache_clear','health_check','maintenance_off'],
                    'comandos_simulados' => [
                        'git fetch origin <branch>',
                        'git rev-list --count HEAD..origin/<branch>',
                        'composer install --no-interaction --prefer-dist',
                        'php artisan migrate --force',
                        'php artisan db:seed --force',
                    ],
                    'preflight' => $this->preflight->report($options),
                ];
                $context['status'] = 'DRY_RUN';
                $this->store->finishRun($runId, $context);
                $this->store->updateRunStatus($runId, 'DRY_RUN');
                $this->store->addRunLog($runId, 'info', 'Plano de dry-run gerado.', $context['dry_run_plan']);
            } else {
                $this->pipeline->run($context);
                $context['status'] = 'success';
                $this->store->finishRun($runId, $context);
            }
            $this->logger->info('updater.run.success', $context);
            $this->reportMailer->sendIfEnabled($context, (string) $context['status']);
            return $context;
        } catch (Throwable $throwable) {
            $context['status'] = 'failed';
            $context['error'] = $throwable->getMessage();
            $this->store->finishRun($runId, $context, ['message' => mb_substr($throwable->getMessage(), 0, 1000)]);
            $this->logger->error('updater.run.failed', $context);
            $this->reportMailer->sendIfEnabled($context, 'failed');
            throw $throwable;
        }
    }

    public function rollback(array $context = []): void
    {
        $this->environmentDetector->ensureCli();
        $this->store->ensureSchema();

        if ($context === []) {
            $last = $this->store->lastRun() ?? [];
            $context = [
                'revision_before' => $last['revision_before'] ?? null,
                'backup_file' => $last['backup_file'] ?? null,
                'snapshot_file' => $last['snapshot_file'] ?? null,
                'options' => [],
            ];
        }

        $runId = $this->store->createRun(['rollback' => true]);
        $context['run_id'] = $runId;
        $this->store->addRunLog($runId, 'warning', 'Iniciando rollback da atualização.');

        try {
            $this->pipeline->rollback($context);
            $this->store->finishRun($runId, $context);
            $this->store->addRunLog($runId, 'info', 'Rollback finalizado com sucesso.');
            $this->logger->warning('updater.rollback.success', $context);
        } catch (Throwable $throwable) {
            $this->store->finishRun($runId, $context, ['message' => mb_substr($throwable->getMessage(), 0, 1000)]);
            $this->store->addRunLog($runId, 'error', 'Rollback falhou.', ['erro' => $throwable->getMessage()]);
            $this->logger->error('updater.rollback.failed', ['error' => $throwable->getMessage()]);
            throw new RollbackException('Rollback falhou: ' . $throwable->getMessage(), previous: $throwable);
        }
    }

    public function status(): array
    {
        $this->store->ensureSchema();

        return [
            'enabled' => (bool) config('updater.enabled', true),
            'mode' => (string) config('updater.mode', 'inplace'),
            'channel' => (string) config('updater.channel', 'stable'),
            'revision' => $this->codeDriver->currentRevision(),
            'last_run' => $this->store->lastRun(),
        ];
    }

    public function stateStore(): StateStore
    {
        return $this->store;
    }
}
