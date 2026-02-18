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

        if (!$enabled || !$this->isPreUpdateBackupEnabled()) {
            return false;
        }

        if ((bool) ($context['options']['no_snapshot'] ?? false)) {
            return false;
        }

        $type = $this->resolveBackupType($context);

        return in_array($type, ['full', 'full+snapshot', 'full+database', 'snapshot'], true);
    }

    public function handle(array &$context): void
    {
        $path = rtrim((string) ($this->config['path'] ?? storage_path('app/updater/snapshots')), '/');
        $this->fileManager->ensureDirectory($path);
        $snapshotBase = $path . '/snapshot_' . date('Ymd_His');

        $excludes = config('updater.paths.exclude_snapshot', []);
        // Exclusões defensivas (evitam recursão do próprio snapshot/backups).
        // Mesmo que o perfil tente incluir storage/app/updater, isso causará arquivo crescendo indefinidamente.
        $excludes[] = "storage/app/updater";
        $excludes[] = "storage/framework/down";
        $excludes[] = ".git";


        // Snapshot deve ser leve e previsível: por padrão exclui storage inteiro.
        // (Uploads relevantes ficam normalmente em public/uploads, já tratado em exclude_snapshot.)
        $excludeStorage = (bool) (function_exists('config') ? config('updater.snapshot.exclude_storage', true) : true);
        if ($excludeStorage) {
            $excludes[] = 'storage';
        }
        $includeVendor = (bool) ($context['options']['snapshot_include_vendor'] ?? ($this->config['include_vendor'] ?? false));
        if (!$includeVendor) {
            $excludes[] = 'vendor';
        }

        $compression = (string) ($context['options']['snapshot_compression'] ?? ($this->config['compression'] ?? 'zip'));

        if ($this->archiveManager !== null) {
            $snapshot = $this->archiveManager->createArchiveFromDirectory(base_path(), $snapshotBase, $compression, array_values(array_unique($excludes)));
        } else {
            $context['snapshot_warning'] = 'ArchiveManager não disponível para snapshot.';
            return;
        }

        $context['snapshot_file'] = $snapshot;
        $runId = (int) ($context['run_id'] ?? 0);
        $this->store->registerArtifact('snapshot', $snapshot, ['run_id' => $runId > 0 ? $runId : null]);

        $insert = $this->store->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, run_id) VALUES (:type,:path,:size,:created_at,:run_id)');
        $insert->execute([
            ':type' => 'snapshot',
            ':path' => $snapshot,
            ':size' => is_file($snapshot) ? (int) filesize($snapshot) : 0,
            ':created_at' => date(DATE_ATOM),
            ':run_id' => $runId > 0 ? $runId : null,
        ]);

        $this->fileManager->deleteOldFiles($path, (int) ($this->config['keep'] ?? 10));
    }



    private function isPreUpdateBackupEnabled(): bool
    {
        if (!function_exists('config')) {
            return true;
        }

        return (bool) config('updater.backup.pre_update', true);
    }

    private function configuredPreUpdateBackupType(): string
    {
        if (!function_exists('config')) {
            return 'full';
        }

        return (string) config('updater.backup.pre_update_type', 'full');
    }

    private function resolveBackupType(array $context): string
    {
        $raw = trim((string) ($context['options']['backup_type'] ?? $this->configuredPreUpdateBackupType()));
        $raw = strtolower(str_replace(' ', '', $raw));

        return match ($raw) {
            'snapshot', 'database', 'full+snapshot', 'full+database', 'full' => $raw,
            default => 'full',
        };
    }

    public function rollback(array &$context): void
    {
        if (empty($context['snapshot_file']) || $this->archiveManager === null) {
            return;
        }

        $this->archiveManager->extractArchive((string) $context['snapshot_file'], base_path());
    }
}
