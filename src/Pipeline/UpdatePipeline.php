<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Exceptions\PipelineException;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\RunDiagnosticsService;
use Psr\Log\LoggerInterface;
use Throwable;

class UpdatePipeline
{
    /** @var array<int, PipelineStepInterface> */
    private array $executed = [];

    /** @param array<int, PipelineStepInterface> $steps */
    public function __construct(private readonly array $steps, private readonly LoggerInterface $logger, private readonly StateStore $store)
    {
        $this->store->ensureSchema();
    }

    public function run(array &$context): void
    {
        $this->store->ensureSchema();
        $this->executed = [];

        foreach ($this->steps as $step) {
            if (!$step->shouldRun($context)) {
                continue;
            }

            $runId = (int) ($context['run_id'] ?? 0);
            if ($runId > 0) {
                $this->store->setRunStep($runId, $step->name());
            }

            $startedNs = hrtime(true);
            $this->logger->info('pipeline.step.start', ['run_id' => $runId, 'step' => $step->name()]);
            $this->store->addRunLog($runId, 'info', 'Iniciando etapa da atualização.', [
                'etapa' => $step->name(),
                'event' => 'start',
            ]);
            try {
                $step->handle($context);
                $this->executed[] = $step;
                if ($runId > 0) {
                    $this->store->setRunStep($runId, null);
                }
                $duration = (int) round((hrtime(true) - $startedNs) / 1000000);
                $details = RunDiagnosticsService::stageDetails($step->name(), $context);
                $this->logger->info('pipeline.step.success', ['run_id' => $runId, 'step' => $step->name(), 'duration_ms' => $duration]);
                $this->store->addRunLog($runId, 'info', 'Etapa concluída com sucesso.', [
                    'etapa' => $step->name(),
                    'event' => 'success',
                    'duration_ms' => $duration,
                    'details' => $details,
                ]);
            } catch (Throwable $throwable) {
                $duration = (int) round((hrtime(true) - $startedNs) / 1000000);
                $safeError = RunDiagnosticsService::sanitize($throwable->getMessage());
                $this->logger->error('pipeline.step.failure', ['run_id' => $runId, 'step' => $step->name(), 'error' => $safeError, 'duration_ms' => $duration]);
                $this->store->addRunLog($runId, 'error', 'Falha em etapa da atualização.', [
                    'etapa' => $step->name(),
                    'event' => 'failure',
                    'erro' => $safeError,
                    'exception' => get_class($throwable),
                    'duration_ms' => $duration,
                    'details' => RunDiagnosticsService::stageDetails($step->name(), $context),
                ]);
                $this->rollback($context);
                throw new PipelineException('Falha na pipeline: ' . $throwable->getMessage(), previous: $throwable);
            }
        }
    }

    public function rollback(array &$context): void
    {
        $this->store->ensureSchema();

        $runId = (int) ($context['run_id'] ?? 0);

        foreach (array_reverse($this->executed) as $step) {
            try {
                $rollbackNs = hrtime(true);
                if ($runId > 0) {
                    $this->store->setRunStep($runId, 'rollback:' . $step->name());
                }
                $step->rollback($context);
                if ($runId > 0) {
                    $this->store->setRunStep($runId, null);
                }
                $elapsed = (int) round((hrtime(true) - $rollbackNs) / 1000000);
                $this->logger->warning('pipeline.step.rollback', ['run_id' => $runId, 'step' => $step->name(), 'duration_ms' => $elapsed]);
                $this->store->addRunLog($runId, 'warning', 'Rollback aplicado para etapa.', [
                    'etapa' => $step->name(),
                    'event' => 'rollback',
                    'duration_ms' => $elapsed,
                ]);
            } catch (Throwable $throwable) {
                $elapsed = isset($rollbackNs) ? (int) round((hrtime(true) - $rollbackNs) / 1000000) : 0;
                $safeError = RunDiagnosticsService::sanitize($throwable->getMessage());
                $this->logger->error('pipeline.step.rollback_failure', ['run_id' => $runId, 'step' => $step->name(), 'error' => $safeError]);
                $this->store->addRunLog($runId, 'error', 'Falha no rollback da etapa.', [
                    'etapa' => $step->name(),
                    'event' => 'rollback_failure',
                    'erro' => $safeError,
                    'duration_ms' => $elapsed,
                ]);
            }
        }
    }
}
