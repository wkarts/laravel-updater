<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

class SnapshotCodeStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly FileManager $fileManager,
        private readonly StateStore $store,
        private readonly array $config
    ) {
    }

    public function name(): string { return 'snapshot_code'; }

    public function shouldRun(array $context): bool
    {
        $enabled = (bool) ($this->config['enabled'] ?? false);
        return $enabled && !(bool) ($context['options']['no_snapshot'] ?? false);
    }

    public function handle(array &$context): void
    {
        $path = rtrim($this->config['path'], '/');
        $this->fileManager->ensureDirectory($path);
        $snapshot = $path . '/snapshot_' . date('Ymd_His') . '.tar.gz';

        $excludes = config('updater.paths.exclude_snapshot', []);
        $excludeArgs = array_map(static fn (string $item): string => '--exclude=' . escapeshellarg($item), $excludes);
        $command = sprintf('tar -czf %s %s .', escapeshellarg($snapshot), implode(' ', $excludeArgs));

        $this->shellRunner->runOrFail(['bash', '-lc', $command]);
        $context['snapshot_file'] = $snapshot;
        $this->store->registerArtifact('snapshot', $snapshot, ['run_id' => $context['run_id'] ?? null]);
        $this->fileManager->deleteOldFiles($path, (int) ($this->config['keep'] ?? 10));
    }

    public function rollback(array &$context): void
    {
        if (empty($context['snapshot_file']) && !empty($context['revision_before'])) {
            return;
        }

        if (!empty($context['snapshot_file'])) {
            $this->shellRunner->runOrFail(['tar', '-xzf', $context['snapshot_file'], '-C', base_path()]);
        }
    }
}
