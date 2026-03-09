<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\StateStore;

/**
 * Backup FULL oficial do fluxo automático de update.
 *
 * Gera um único artefato "full" por execução (sem registrar backup/snapshot avulsos
 * como fluxo principal), preservando compatibilidade com backups manuais.
 */
class FullBackupStep implements PipelineStepInterface
{
    public function __construct(
        private readonly BackupDriverInterface $backupDriver,
        private readonly FileManager $fileManager,
        private readonly ArchiveManager $archiveManager,
        private readonly StateStore $store,
        private readonly array $snapshotConfig,
        private readonly bool $enabled
    ) {
    }

    public function name(): string { return 'backup_full'; }

    public function shouldRun(array $context): bool
    {
        if (!$this->enabled || !$this->isPreUpdateBackupEnabled()) {
            return false;
        }

        if ((bool) ($context['options']['no_backup'] ?? false)) {
            return false;
        }

        if (!empty($context['full_backup_file'])) {
            return false;
        }

        return $this->resolveBackupType($context) === 'full';
    }

    public function handle(array &$context): void
    {
        $runId = isset($context['run_id']) ? (int) $context['run_id'] : null;
        $profileId = isset($context['options']['profile_id']) ? (int) $context['options']['profile_id'] : null;

        $compression = (string) ($context['options']['snapshot_compression'] ?? $this->snapshotConfig['compression'] ?? 'zip');
        if ($compression === 'auto') {
            $compression = 'zip';
        }

        $includeVendor = (bool) ($context['options']['snapshot_include_vendor'] ?? $this->snapshotConfig['include_vendor'] ?? false);

        $dbFile = $this->backupDriver->backup('db_full_' . date('Ymd_His'));
        $snapshotFile = $this->createSnapshotArtifact($compression, $includeVendor);

        $backupPath = rtrim((string) config('updater.backup.path'), '/');
        $this->fileManager->ensureDirectory($backupPath);
        $fullBase = $backupPath . '/full_' . date('Ymd_His');

        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/updater-full-' . uniqid('', true);
        @mkdir($tmpDir, 0775, true);
        @mkdir($tmpDir . '/database', 0775, true);
        @mkdir($tmpDir . '/snapshot', 0775, true);

        @copy($dbFile, $tmpDir . '/database/' . basename($dbFile));
        @copy($snapshotFile, $tmpDir . '/snapshot/' . basename($snapshotFile));

        $fullPath = $this->archiveManager->createArchiveFromDirectory($tmpDir, $fullBase, $compression, []);

        $this->deletePath($tmpDir);
        $this->deletePath($dbFile);
        $this->deletePath($snapshotFile);

        $this->store->registerArtifact('full', $fullPath, ['run_id' => $runId]);
        $this->registerFullBackupRow($fullPath, $profileId, $runId, $context);

        // Artefato oficial da execução.
        $context['full_backup_file'] = $fullPath;
        $context['backup_file'] = $fullPath;
    }

    private function createSnapshotArtifact(string $compression, bool $includeVendor): string
    {
        $path = rtrim((string) ($this->snapshotConfig['path'] ?? storage_path('app/updater/snapshots')), '/');
        $this->fileManager->ensureDirectory($path);

        $base = $path . '/snapshot_full_' . date('Ymd_His');
        $excludes = (array) config('updater.paths.exclude_snapshot', []);
        $excludes[] = '.git';
        $excludes[] = '.git/';
        $excludes[] = 'storage/app/updater';
        $excludes[] = 'storage/framework/down';

        if ((bool) config('updater.snapshot.exclude_storage', true)) {
            $excludes[] = 'storage';
            $excludes[] = 'storage/';
        }

        if (!$includeVendor) {
            $excludes[] = 'vendor';
        }

        return $this->archiveManager->createArchiveFromDirectory(base_path(), $base, $compression, array_values(array_unique($excludes)));
    }

    private function registerFullBackupRow(string $fullPath, ?int $profileId, ?int $runId, array &$context): void
    {
        if ($fullPath === '' || !is_file($fullPath)) {
            return;
        }

        try {
            $stmt = $this->store->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, profile_id, run_id, cloud_uploaded, cloud_upload_count) VALUES (:type,:path,:size,:created_at,:profile_id,:run_id,0,0)');
            $stmt->execute([
                ':type' => 'full',
                ':path' => $fullPath,
                ':size' => (int) filesize($fullPath),
                ':created_at' => date(DATE_ATOM),
                ':profile_id' => $profileId ?: null,
                ':run_id' => $runId ?: null,
            ]);
        } catch (\Throwable $e) {
            $context['full_backup_register_warning'] = $e->getMessage();
        }
    }

    private function isPreUpdateBackupEnabled(): bool
    {
        return (bool) config('updater.backup.pre_update', true);
    }

    private function resolveBackupType(array $context): string
    {
        $raw = trim((string) ($context['options']['backup_type'] ?? config('updater.backup.pre_update_type', 'full')));
        $raw = strtolower(str_replace(' ', '', $raw));

        return in_array($raw, ['snapshot', 'database', 'full+snapshot', 'full+database', 'full'], true)
            ? $raw
            : 'full';
    }

    private function deletePath(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deletePath($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }

    public function rollback(array &$context): void
    {
        // Backup não precisa de rollback.
    }
}
