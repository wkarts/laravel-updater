<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Exceptions\PipelineException;
use Argws\LaravelUpdater\Support\StateStore;
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

        foreach ($this->steps as $step) {
            if (!$step->shouldRun($context)) {
                continue;
            }

            $this->logger->info('pipeline.step.start', ['step' => $step->name()]);
            $this->store->addRunLog((int) ($context['run_id'] ?? 0), 'info', 'Iniciando etapa da atualização.', ['etapa' => $step->name()]);
            try {
                $step->handle($context);
                $this->executed[] = $step;
                $this->logger->info('pipeline.step.success', ['step' => $step->name()]);
                $this->store->addRunLog((int) ($context['run_id'] ?? 0), 'info', 'Etapa concluída com sucesso.', ['etapa' => $step->name()]);
            } catch (Throwable $throwable) {
                $this->logger->error('pipeline.step.failure', ['step' => $step->name(), 'error' => $throwable->getMessage()]);
                $this->store->addRunLog((int) ($context['run_id'] ?? 0), 'error', 'Falha em etapa da atualização.', ['etapa' => $step->name(), 'erro' => $throwable->getMessage()]);
                $this->rollback($context);
                throw new PipelineException('Falha na pipeline: ' . $throwable->getMessage(), previous: $throwable);
            }
        }
    }

    public function rollback(array &$context): void
    {
        $this->store->ensureSchema();

        foreach (array_reverse($this->executed) as $step) {
            try {
                $step->rollback($context);
                $this->logger->warning('pipeline.step.rollback', ['step' => $step->name()]);
                $this->store->addRunLog((int) ($context['run_id'] ?? 0), 'warning', 'Rollback aplicado para etapa.', ['etapa' => $step->name()]);
            } catch (Throwable $throwable) {
                $this->logger->error('pipeline.step.rollback_failure', ['step' => $step->name(), 'error' => $throwable->getMessage()]);
                $this->store->addRunLog((int) ($context['run_id'] ?? 0), 'error', 'Falha no rollback da etapa.', ['etapa' => $step->name(), 'erro' => $throwable->getMessage()]);
            }
        }
    }
}
