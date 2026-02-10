<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class SeedStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'seed'; }

    public function shouldRun(array $context): bool
    {
        return (bool) ($context['options']['seed'] ?? false) || !empty($context['options']['seeders']);
    }

    public function handle(array &$context): void
    {
        $seeders = $context['options']['seeders'] ?? [];
        if ($seeders === []) {
            $this->shellRunner->runOrFail(['php', 'artisan', 'db:seed', '--force']);
            return;
        }

        foreach ($seeders as $seeder) {
            $this->shellRunner->runOrFail(['php', 'artisan', 'db:seed', '--class=' . $seeder, '--force']);
        }
    }

    public function rollback(array &$context): void
    {
    }
}
