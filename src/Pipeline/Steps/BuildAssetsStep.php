<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class BuildAssetsStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly bool $enabled)
    {
    }

    public function name(): string { return 'build_assets'; }

    public function shouldRun(array $context): bool
    {
        return $this->enabled && !(bool) ($context['options']['no_build'] ?? false);
    }

    public function handle(array &$context): void
    {
        $this->shellRunner->runOrFail(['npm', 'ci']);
        $this->shellRunner->runOrFail(['npm', 'run', 'build']);
    }

    public function rollback(array &$context): void
    {
    }
}
