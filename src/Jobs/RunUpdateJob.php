<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Jobs;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunUpdateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $options = [])
    {
    }

    public function handle(UpdaterKernel $kernel, ?ManagerStore $managerStore = null): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException(
                'RunUpdateJob recusado fora do CLI. Queue síncrona não pode executar atualização real dentro do PHP-FPM.'
            );
        }

        $managerStore ??= app(ManagerStore::class);
        $options = $this->options;
        $sourceId = (int) ($options['source_id'] ?? 0);
        if ($sourceId > 0 && $managerStore->findSource($sourceId) === null) {
            throw new \RuntimeException('A fonte selecionada para esta execução não está mais disponível.');
        }
        $profileId = (int) ($options['profile_id'] ?? 0);
        $profile = $profileId > 0 ? $managerStore->findProfile($profileId) : $managerStore->activeProfile();
        if ($profileId > 0 && $profile === null) {
            throw new \RuntimeException('O perfil selecionado para esta execução não está mais disponível.');
        }
        // Resolve trusted server-side settings when the worker starts. Do not change
        // globally active selections: another run may already be holding the lock.
        $options['profile_id'] = $profile['id'] ?? null;
        $options['dry_run'] = array_key_exists('dry_run', $options) ? (bool) $options['dry_run'] : (bool) ($profile['dry_run'] ?? false);
        $options['rollback_on_fail'] = (bool) ($profile['rollback_on_fail'] ?? $options['rollback_on_fail'] ?? true);
        $options['snapshot_include_vendor'] = (bool) ($profile['snapshot_include_vendor'] ?? $options['snapshot_include_vendor'] ?? config('updater.snapshot.include_vendor', false));
        $options['snapshot_compression'] = (string) ($profile['snapshot_compression'] ?? $options['snapshot_compression'] ?? config('updater.snapshot.compression', 'zip'));
        $options['seed'] = (bool) ($options['seed'] ?? false);
        foreach (['pre_update_commands', 'post_update_commands'] as $field) {
            $commands = array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($profile[$field] ?? '')) ?: []);
            $commands = array_values(array_filter($commands, static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
            if ($commands === []) {
                $raw = (string) env('UPDATER_' . strtoupper($field), '');
                $commands = array_map('trim', preg_split('/;;|\r\n|\r|\n/', $raw) ?: []);
            }
            $options[$field] = array_values(array_unique(array_filter($commands, static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'))));
        }
        $kernel->run($options);
    }
}
