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
        $hasManualSeed = (bool) ($context['options']['seed'] ?? false) || !empty($context['options']['seeders']);
        $runReforma = $this->cfg('updater.seed.run_reforma_tributaria', true);

        return $hasManualSeed || $runReforma;
    }

    public function handle(array &$context): void
    {
        $seeders = $this->resolveSeeders($context['options'] ?? []);

        foreach ($seeders as $seeder) {
            $checksum = hash('sha256', (string) $seeder);
            $forceReapply = (bool) ($context['options']['force_seed_reapply'] ?? false);

            if (!$forceReapply && $this->stateStore?->hasSeedApplied((string) $seeder, $checksum)) {
                $context['seed_log'][] = $seeder . ': já aplicado';
                continue;
            }

            if (!class_exists((string) $seeder)) {
                $context['seed_log'][] = $seeder . ': não encontrado, ignorado';
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

    /** @return array<int, string> */
    private function resolveSeeders(array $options): array
    {
        $seeders = array_values(array_filter((array) ($options['seeders'] ?? [])));

        $requestedDefaultSeed = (bool) ($options['seed'] ?? false);
        $allowDefaultSeed = (bool) ($options['install_seed_default'] ?? false)
            || $this->cfg('updater.seed.allow_default_database_seeder', false);

        if ($requestedDefaultSeed && $seeders === [] && $allowDefaultSeed) {
            $seeders[] = 'Database\Seeders\DatabaseSeeder';
        }

        if ($this->cfg('updater.seed.run_reforma_tributaria', true)) {
            $reformaSeeder = (string) $this->cfg('updater.seed.reforma_tributaria_seeder', 'Database\Seeders\ReformaTributariaSeeder');
            if ($reformaSeeder !== '' && !in_array($reformaSeeder, $seeders, true)) {
                $seeders[] = $reformaSeeder;
            }
        }

        return $seeders;
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        try {
            if (function_exists('config')) {
                return config($key, $default);
            }
        } catch (\Throwable) {
            // fallback
        }

        return $default;
    }
}
