<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\GitMaintenance;

class GitMaintenanceStep implements PipelineStepInterface
{
    public function __construct(private readonly GitMaintenance $maintenance, private readonly string $reason)
    {
    }

    public function name(): string
    {
        return 'git_maintenance_' . $this->reason;
    }

    public function run(array $context): array
    {
        $report = $this->maintenance->maintain($this->reason);

        // Não falha a pipeline por manutenção; registra apenas.
        $context['git_maintenance'][$this->reason] = $report;

        return $context;
    }
}
