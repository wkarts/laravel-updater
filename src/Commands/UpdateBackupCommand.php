<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\BackupCloudUploader;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Console\Command;
use Throwable;

class UpdateBackupCommand extends Command
{
    protected $signature = 'system:update:backup {--type=database : Tipo do backup (database|snapshot|full)} {--run-id= : Run ID já criado pela UI}';

    protected $description = 'Executa backup manual do updater (modo assíncrono).';

    public function handle(
        StateStore $stateStore,
        BackupDriverInterface $backupDriver,
        FileManager $fileManager,
        ArchiveManager $archiveManager,
        BackupCloudUploader $cloudUploader,
        ManagerStore $managerStore
    ): int {
        $stateStore->ensureSchema();

        $type = strtolower(trim((string) $this->option('type')));
        if (!in_array($type, ['database', 'snapshot', 'full'], true)) {
            $this->error('Tipo de backup inválido.');

            return self::FAILURE;
        }

        $runId = (int) ($this->option('run-id') ?: 0);
        if ($runId <= 0) {
            $runId = $stateStore->createRun(['manual_backup' => $type]);
        }

        $pid = getmypid() ?: null;
        $managerStore->setRuntimeOption('backup_active_job', [
            'run_id' => $runId,
            'type' => $type,
            'pid' => $pid,
            'status' => 'running',
            'started_at' => date(DATE_ATOM),
        ]);

        try {
            $stateStore->addRunLog($runId, 'info', 'Iniciando backup manual.', ['tipo' => $type]);

            $created = match ($type) {
                'database' => $this->createDatabaseBackup($backupDriver, $stateStore, $managerStore, $cloudUploader, $runId),
                'snapshot' => $this->createSnapshotBackup($fileManager, $archiveManager, $stateStore, $managerStore, $cloudUploader, $runId),
                'full' => $this->createFullBackup($backupDriver, $fileManager, $archiveManager, $stateStore, $managerStore, $cloudUploader, $runId),
            };

            $stateStore->finishRun($runId, ['revision_before' => null, 'revision_after' => null]);
            $stateStore->addRunLog($runId, 'info', 'Backup manual finalizado com sucesso.', [
                'tipo' => $type,
                'backup_id' => $created['id'],
                'arquivo' => $created['path'],
            ]);

            $managerStore->setRuntimeOption('backup_active_job', [
                'run_id' => $runId,
                'type' => $type,
                'pid' => $pid,
                'status' => 'finished',
                'finished_at' => date(DATE_ATOM),
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $stateStore->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);
            $stateStore->addRunLog($runId, 'error', 'Falha ao gerar backup manual.', [
                'tipo' => $type,
                'erro' => $e->getMessage(),
            ]);
            $managerStore->setRuntimeOption('backup_active_job', [
                'run_id' => $runId,
                'type' => $type,
                'pid' => $pid,
                'status' => 'failed',
                'finished_at' => date(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    private function createDatabaseBackup(BackupDriverInterface $backupDriver, StateStore $stateStore, ManagerStore $managerStore, BackupCloudUploader $cloudUploader, int $runId): array
    {
        $name = 'manual-db-' . date('Ymd-His');
        $filePath = $backupDriver->backup($name);

        return $this->insertBackupRow('database', $filePath, $runId, $stateStore, $managerStore, $cloudUploader);
    }

    private function createSnapshotBackup(FileManager $fileManager, ArchiveManager $archiveManager, StateStore $stateStore, ManagerStore $managerStore, BackupCloudUploader $cloudUploader, int $runId): array
    {
        $snapshotPath = rtrim((string) config('updater.snapshot.path'), '/');
        $fileManager->ensureDirectory($snapshotPath);

        $filePath = $snapshotPath . '/manual-snapshot-' . date('Ymd-His') . '.zip';
        $excludes = config('updater.paths.exclude_snapshot', []);
        if (!(bool) config('updater.snapshot.include_vendor', false)) {
            $excludes[] = 'vendor';
        }
        $archiveManager->createZipFromDirectory(base_path(), $filePath, array_values(array_unique($excludes)));

        return $this->insertBackupRow('snapshot', $filePath, $runId, $stateStore, $managerStore, $cloudUploader);
    }

    private function createFullBackup(BackupDriverInterface $backupDriver, FileManager $fileManager, ArchiveManager $archiveManager, StateStore $stateStore, ManagerStore $managerStore, BackupCloudUploader $cloudUploader, int $runId): array
    {
        $db = $this->createDatabaseBackup($backupDriver, $stateStore, $managerStore, $cloudUploader, $runId);
        $snapshot = $this->createSnapshotBackup($fileManager, $archiveManager, $stateStore, $managerStore, $cloudUploader, $runId);

        $fullPath = rtrim((string) config('updater.backup.path'), '/') . '/manual-full-' . date('Ymd-His') . '.zip';
        $archiveManager->createZipFromFiles([
            (string) $db['path'] => 'database/' . basename((string) $db['path']),
            (string) $snapshot['path'] => 'snapshot/' . basename((string) $snapshot['path']),
        ], $fullPath);

        return $this->insertBackupRow('full', $fullPath, $runId, $stateStore, $managerStore, $cloudUploader);
    }

    private function insertBackupRow(string $type, string $path, int $runId, StateStore $stateStore, ManagerStore $managerStore, BackupCloudUploader $cloudUploader): array
    {
        $stmt = $stateStore->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, run_id) VALUES (:type,:path,:size,:created_at,:run_id)');
        $stmt->execute([
            ':type' => $type,
            ':path' => $path,
            ':size' => is_file($path) ? (int) filesize($path) : 0,
            ':created_at' => date(DATE_ATOM),
            ':run_id' => $runId,
        ]);

        $backupId = (int) $stateStore->pdo()->lastInsertId();
        $upload = $managerStore->backupUploadSettings();
        if ((bool) ($upload['auto_upload'] ?? false) && trim((string) ($upload['provider'] ?? 'none')) !== 'none' && is_file($path)) {
            try {
                $result = $cloudUploader->upload($path, $upload);
                $provider = (string) ($result['provider'] ?? ($upload['provider'] ?? 'n/a'));
                $remote = (string) ($result['remote_path'] ?? '');

                $mark = $stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_uploaded = 1, cloud_provider = :provider, cloud_uploaded_at = :uploaded_at, cloud_remote_path = :remote_path, cloud_upload_count = COALESCE(cloud_upload_count, 0) + 1, cloud_last_error = NULL WHERE id = :id');
                $mark->execute([
                    ':provider' => $provider,
                    ':uploaded_at' => date(DATE_ATOM),
                    ':remote_path' => $remote,
                    ':id' => $backupId,
                ]);

                $stateStore->addRunLog($runId, 'info', 'Backup enviado para nuvem com sucesso.', [
                    'backup_id' => $backupId,
                    'provider' => $provider,
                    'remoto' => $remote,
                ]);
            } catch (Throwable $e) {
                $markErr = $stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_last_error = :err WHERE id = :id');
                $markErr->execute([':err' => $e->getMessage(), ':id' => $backupId]);
                $stateStore->addRunLog($runId, 'warning', 'Upload automático em nuvem falhou, backup local mantido.', [
                    'backup_id' => $backupId,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return ['id' => $backupId, 'type' => $type, 'path' => $path];
    }
}
