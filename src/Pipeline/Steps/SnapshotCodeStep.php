<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ShellRunner;

class SnapshotCodeStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly FileManager $fileManager, private readonly array $config)
    {
    }

    public function name(): string { return 'snapshot_code'; }
    public function shouldRun(array $context): bool { return (bool) ($this->config['enabled'] ?? false); }

    public function handle(array &$context): void
    {
        $path = rtrim($this->config['path'], '/');
        $this->fileManager->ensureDirectory($path);
        $snapshot = $path . '/snapshot_' . date('Ymd_His') . '.tar.gz';
        $this->shellRunner->runOrFail(['tar', '-czf', $snapshot, '--exclude=vendor', '.']);
        $context['snapshot_file'] = $snapshot;
        $this->fileManager->deleteOldFiles($path, (int) ($this->config['keep'] ?? 10));
    }

    public function rollback(array &$context): void
    {
        if (empty($context['snapshot_file'])) {
            return;
        }

        $this->shellRunner->runOrFail(['tar', '-xzf', $context['snapshot_file'], '-C', base_path()]);
    }
}
