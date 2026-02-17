<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

class SnapshotCodeStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly FileManager $fileManager,
        private readonly StateStore $store,
        private readonly array $config,
        private readonly ?ArchiveManager $archiveManager = null
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
        $path = rtrim((string) ($this->config['path'] ?? storage_path('app/updater/snapshots')), '/');
        $this->fileManager->ensureDirectory($path);
        $snapshotBase = $path . '/snapshot_' . date('Ymd_His');

        $excludes = config('updater.paths.exclude_snapshot', []);
        $includeVendor = (bool) ($context['options']['snapshot_include_vendor'] ?? ($this->config['include_vendor'] ?? false));
        if (!$includeVendor) {
            $excludes[] = 'vendor';
        }

        $compression = (string) ($context['options']['snapshot_compression'] ?? ($this->config['compression'] ?? 'auto'));

        if ($this->archiveManager !== null) {
            $snapshot = $this->archiveManager->createArchiveFromDirectory(base_path(), $snapshotBase, $compression, array_values(array_unique($excludes)));
        } else {
            $context['snapshot_warning'] = 'ArchiveManager não disponível para snapshot.';
            return;
        }

        $context['snapshot_file'] = $snapshot;
        $runId = (int) ($context['run_id'] ?? 0);
        $this->store->registerArtifact('snapshot', $snapshot, ['run_id' => $runId > 0 ? $runId : null]);

        $insert = $this->store->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, run_id, cloud_uploaded, cloud_upload_count) VALUES (:type,:path,:size,:created_at,:run_id,0,0)');
        $insert->execute([
            ':type' => 'snapshot',
            ':path' => $snapshot,
            ':size' => is_file($snapshot) ? (int) filesize($snapshot) : 0,
            ':created_at' => date(DATE_ATOM),
            ':run_id' => $runId > 0 ? $runId : null,
        ]);

        $this->fileManager->deleteOldFiles($path, (int) ($this->config['keep'] ?? 10));
    }

    public function rollback(array &$context): void
    {
        if (empty($context['snapshot_file']) || $this->archiveManager === null) {
            return;
        }

        $this->archiveManager->extractArchive((string) $context['snapshot_file'], base_path());
    }
}
