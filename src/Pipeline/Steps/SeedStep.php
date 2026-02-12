<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

class SeedStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly ?StateStore $stateStore = null)
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
            $seeders = ['Database\\Seeders\\DatabaseSeeder'];
        }

        foreach ($seeders as $seeder) {
            $checksum = hash('sha256', (string) $seeder);
            $forceReapply = (bool) ($context['options']['force_seed_reapply'] ?? false);

            if (!$forceReapply && $this->stateStore?->hasSeedApplied((string) $seeder, $checksum)) {
                $context['seed_log'][] = $seeder . ': já aplicado';
                continue;
            }

            if ((bool) ($context['options']['dry_run'] ?? false)) {
                $context['dry_run_plan']['seeders'][] = 'php artisan db:seed --class=' . $seeder . ' --force';
                continue;
            }

            $this->shellRunner->runOrFail(['php', 'artisan', 'db:seed', '--class=' . $seeder, '--force']);
            $this->stateStore?->registerSeed((string) $seeder, $checksum, $context['revision_after'] ?? null, 'Aplicado via updater');
            $context['seed_log'][] = $seeder . ': aplicado';
        }
    }

    public function rollback(array &$context): void
    {
    }
}
