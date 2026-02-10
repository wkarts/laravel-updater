<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;

class GitUpdateStep implements PipelineStepInterface
{
    public function __construct(private readonly CodeDriverInterface $codeDriver)
    {
    }

    public function name(): string { return 'git_update'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $context['revision_before'] = $this->codeDriver->currentRevision();
        $context['revision_after'] = $this->codeDriver->update();
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['revision_before'])) {
            $this->codeDriver->rollback($context['revision_before']);
        }
    }
}
