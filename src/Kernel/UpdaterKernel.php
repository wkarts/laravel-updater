<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Kernel;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Exceptions\RollbackException;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Pipeline\Steps\BuildAssetsStep;
use Argws\LaravelUpdater\Pipeline\Steps\CacheClearStep;
use Argws\LaravelUpdater\Pipeline\Steps\FullBackupStep;
use Argws\LaravelUpdater\Pipeline\Steps\ComposerInstallStep;
use Argws\LaravelUpdater\Pipeline\Steps\GitUpdateStep;
use Argws\LaravelUpdater\Pipeline\Steps\Psr4SanitizeStep;
use Argws\LaravelUpdater\Pipeline\Steps\HealthCheckStep;
use Argws\LaravelUpdater\Pipeline\Steps\LockStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOffStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOnStep;
use Argws\LaravelUpdater\Pipeline\Steps\PostUpdateCommandsStep;
use Argws\LaravelUpdater\Pipeline\Steps\PreUpdateCommandsStep;
use Argws\LaravelUpdater\Pipeline\Steps\MigrateStep;
use Argws\LaravelUpdater\Pipeline\Steps\SeedStep;
use Argws\LaravelUpdater\Pipeline\Steps\SqlPatchStep;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Argws\LaravelUpdater\Support\RunDiagnosticsService;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
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
        private readonly RunReportMailer $reportMailer,
        private readonly UpdateRecoveryManager $recovery
    ) {
    }

    public static function makePipeline(array $services): UpdatePipeline
    {
        // A janela de indisponibilidade começa somente após o backup FULL.
        // Manutenção do repositório Git (gc/prune/aggressive) NÃO pertence ao
        // caminho crítico da atualização; continua disponível via scheduler/manual.
        $steps = [
            new LockStep($services['lock'], (int) config('updater.lock.timeout', 600)),
            new FullBackupStep(
                $services['backup'],
                $services['files'],
                $services['archive'],
                $services['store'],
                (array) config('updater.snapshot', []),
                (bool) config('updater.backup.enabled', true)
            ),
            new MaintenanceOnStep($services['shell'], $services['maintenance_mode'] ?? null),
            new PreUpdateCommandsStep($services['shell']),
            new GitUpdateStep($services['code'], $services['manager_store'] ?? null, $services['shell']),
            new Psr4SanitizeStep($services['psr4_sanitizer'], $services['store'], $services['logger']),
            new ComposerInstallStep($services['shell']),
            new MigrateStep($services['shell']),
            new SeedStep($services['shell'], $services['store']),
            new SqlPatchStep((string) config('updater.patches.path'), $services['store']),
            new BuildAssetsStep($services['shell'], (bool) config('updater.build_assets', false)),
            // Scripts posteriores podem atualizar vendor/provedores; gerar cache de
            // rotas apenas depois deles evita readiness 404 por rota desatualizada.
            new PostUpdateCommandsStep($services['shell']),
            new CacheClearStep($services['shell']),
            new HealthCheckStep(config('updater.healthcheck')),
            new MaintenanceOffStep($services['shell'], $services['lock']),
        ];

        return new UpdatePipeline($steps, $services['logger'], $services['store']);
    }

    public function check(bool $allowDirty = false): array
    {
        $this->store->ensureSchema();
        // "check" é somente leitura. Por padrão, não bloqueie a consulta por repositório dirty,
        // senão a UI fica inutilizável quando há alterações locais (muito comum em produção).
        if ($allowDirty === false) {
            $allowDirty = (bool) config('updater.git.allow_dirty_check', true);
        }

        $this->preflight->validate(['allow_dirty' => $allowDirty]);

        return [
            'enabled' => (bool) config('updater.enabled', true),
            'current_revision' => $this->codeDriver->currentRevision(),
            ...$this->codeDriver->statusUpdates(),
        ];
    }

    public function run(array $options = []): array
    {
        $isDryRun = (bool) ($options['dry_run'] ?? false);

        // Update REAL nunca pode executar em FPM/Apache/HTTP. O allow_http fica
        // restrito ao dry-run de compatibilidade. Esta é a última barreira contra
        // queue=sync ou qualquer dispatcher que tente executar a pipeline web.
        $allowHttpDryRun = $isDryRun && (bool) ($options['allow_http'] ?? false);
        $this->environmentDetector->ensureCli($allowHttpDryRun);
        $this->store->ensureSchema();
        $requestedRunId = (int) ($options['run_id'] ?? 0);
        $existingRun = $requestedRunId > 0 ? $this->store->findRun($requestedRunId) : null;

        if (is_array($existingRun) && in_array((string) ($existingRun['status'] ?? ''), ['queued', 'running'], true)) {
            $runId = $requestedRunId;
            $this->store->startRun($runId, getmypid() ?: null, PHP_SAPI);
        } else {
            $runId = $this->store->createRun($options);
        }

        $context = [
            'run_id' => $runId,
            'options' => $options,
            'started_at' => now()->toIso8601String(),
            'idempotency_key' => hash('sha256', $runId . ':' . now()->timestamp),
        ];

        $this->recovery->registerFatalGuard($runId);

        try {
            if (!$isDryRun) {
                $this->preflight->validate($options);
                // O bootstrap do repositório (git init/remote/fetch) é responsabilidade do CodeDriver.
                // Se o auto-init estiver desativado, a falha ocorrerá no step git_update.
            }

            if ($isDryRun) {
                $requestedType = trim((string) ($options['update_type'] ?? config('updater.git.update_type', 'git_ff_only')));
                $requestedTag = trim((string) ($options['target_tag'] ?? ''));
                $requestedBranch = trim((string) ($options['target_branch'] ?? ''));
                $sourceId = (int) ($options['source_id'] ?? 0);
                $profileId = (int) ($options['profile_id'] ?? 0);
                $hasRequestedTarget = $sourceId > 0 || $requestedTag !== '' || $requestedBranch !== '';
                $requestedReference = $requestedType === 'git_tag'
                    ? ($requestedTag !== '' ? $requestedTag : null)
                    : ($requestedBranch !== '' ? $requestedBranch : null);

                // statusUpdates describes the repository currently configured in
                // the driver. It cannot prove the commit or distance of another
                // selected source/tag/branch without preparing that context.
                // A dry-run must not mutate Git to manufacture that comparison.
                $status = $hasRequestedTarget ? [] : $this->codeDriver->statusUpdates();
                $context['dry_run_plan'] = [
                    'versao_atual' => $this->codeDriver->currentRevision(),
                    'versao_alvo' => $hasRequestedTarget ? $requestedReference : ($status['remote'] ?? null),
                    'diff_commits' => $hasRequestedTarget ? null : ($status['behind_by_commits'] ?? null),
                    'alvo_solicitado' => [
                        'source_id' => $sourceId > 0 ? $sourceId : null,
                        'profile_id' => $profileId > 0 ? $profileId : null,
                        'update_type' => $requestedType,
                        'target_tag' => $requestedTag !== '' ? $requestedTag : null,
                        'target_branch' => $requestedBranch !== '' ? $requestedBranch : null,
                        'reference' => $requestedReference,
                        'commit' => null,
                        'verification' => 'not_verified',
                    ],
                    'comparacao' => [
                        'disponivel' => !$hasRequestedTarget && isset($status['remote'], $status['behind_by_commits']),
                        'escopo' => $hasRequestedTarget ? 'alvo_solicitado_nao_verificado' : 'repositorio_atualmente_configurado',
                        'mensagem' => $hasRequestedTarget
                            ? 'Referência solicitada preservada. O commit e a distância do alvo não foram verificados; nenhuma preparação ou alteração Git foi executada.'
                            : 'A consulta descreve somente o repositório atualmente configurado, sem validar uma fonte ou referência alternativa.',
                    ],
                    'steps' => ['lock','backup_full','pre_update_commands','maintenance_on','git_update','psr4_sanitize','composer_install','migrate','seed','sql_patch','build_assets','post_update_commands','cache_clear','health_check','maintenance_off'],
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

            $this->store->addRunLog($runId, 'info', 'Execução finalizada com sucesso.', [
                'run_id' => $runId,
                'revision_after' => $context['revision_after'] ?? null,
            ]);
            $this->logger->info('updater.run.success', RunDiagnosticsService::sanitize($context));
            $this->reportMailer->sendIfEnabled($context, (string) $context['status']);

            return $context;
        } catch (Throwable $throwable) {
            $context['status'] = 'failed';
            $context['error'] = $throwable->getMessage();
            $this->store->finishRun($runId, $context, ['message' => mb_substr($throwable->getMessage(), 0, 1000)]);
            $this->store->addRunLog($runId, 'error', 'Falha geral da execução.', [
                'run_id' => $runId,
                'error' => RunDiagnosticsService::sanitize($throwable->getMessage()),
                'exception' => get_class($throwable),
            ]);
            $this->logger->error('updater.run.failed', RunDiagnosticsService::sanitize($context));
            $this->reportMailer->sendIfEnabled($context, 'failed');

            throw $throwable;
        } finally {
            $this->recovery->disarmFatalGuard($runId);
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
