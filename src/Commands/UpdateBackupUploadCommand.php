<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Support\BackupCloudUploader;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Console\Command;
use Throwable;

class UpdateBackupUploadCommand extends Command
{
    protected $signature = 'system:update:backup-upload {--backup-id= : ID do backup}';

    protected $description = 'Envia backup para nuvem em segundo plano.';

    public function handle(StateStore $stateStore, ManagerStore $managerStore, BackupCloudUploader $cloudUploader): int
    {
        $stateStore->ensureSchema();
        $backupId = (int) ($this->option('backup-id') ?: 0);
        if ($backupId <= 0) {
            $this->error('Informe --backup-id válido.');
            return self::FAILURE;
        }

        $stmt = $stateStore->pdo()->prepare('SELECT * FROM updater_backups WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $backupId]);
        $backup = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!is_array($backup)) {
            $this->error('Backup não encontrado.');
            return self::FAILURE;
        }

        $path = (string) ($backup['path'] ?? '');
        if (!is_file($path)) {
            $this->error('Arquivo local não encontrado para upload.');
            return self::FAILURE;
        }

        $settings = $managerStore->backupUploadSettings();
        if ((string) ($settings['provider'] ?? 'none') === 'none') {
            $this->error('Nenhum provedor configurado.');
            return self::FAILURE;
        }

        $pid = getmypid() ?: null;
        $initialJob = [
            'backup_id' => $backupId,
            'pid' => $pid,
            'status' => 'running',
            'progress' => 5,
            'message' => 'Preparando upload...',
            'started_at' => date(DATE_ATOM),
        ];
        $this->persistUploadJob($managerStore, $backupId, $initialJob);

        try {
            $runningJob = [
                'backup_id' => $backupId,
                'pid' => $pid,
                'status' => 'running',
                'progress' => 25,
                'message' => 'Enviando arquivo para nuvem...',
                'started_at' => date(DATE_ATOM),
            ];
            $this->persistUploadJob($managerStore, $backupId, $runningJob);

            $result = $cloudUploader->upload($path, $settings);
            $provider = (string) ($result['provider'] ?? ($settings['provider'] ?? 'n/a'));
            $remotePath = (string) ($result['remote_path'] ?? '');

            $up = $stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_uploaded = 1, cloud_provider = :provider, cloud_uploaded_at = :uploaded_at, cloud_remote_path = :remote_path, cloud_upload_count = COALESCE(cloud_upload_count,0) + 1, cloud_last_error = NULL WHERE id = :id');
            $up->execute([
                ':provider' => $provider,
                ':uploaded_at' => date(DATE_ATOM),
                ':remote_path' => $remotePath,
                ':id' => $backupId,
            ]);

            $finishedJob = [
                'backup_id' => $backupId,
                'pid' => $pid,
                'status' => 'finished',
                'progress' => 100,
                'message' => 'Upload concluído com sucesso.',
                'provider' => $provider,
                'finished_at' => date(DATE_ATOM),
            ];
            $this->persistUploadJob($managerStore, $backupId, $finishedJob);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $err = $stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_last_error = :error WHERE id = :id');
            $err->execute([':error' => $e->getMessage(), ':id' => $backupId]);

            $failedJob = [
                'backup_id' => $backupId,
                'pid' => $pid,
                'status' => 'failed',
                'progress' => 100,
                'message' => 'Falha no upload: ' . $e->getMessage(),
                'finished_at' => date(DATE_ATOM),
            ];
            $this->persistUploadJob($managerStore, $backupId, $failedJob);
            return self::FAILURE;
        }
    }

    private function persistUploadJob(ManagerStore $managerStore, int $backupId, array $job): void
    {
        $managerStore->setRuntimeOption('backup_upload_active_job', $job);
        $jobs = $managerStore->getRuntimeOption('backup_upload_jobs', []);
        if (!is_array($jobs)) {
            $jobs = [];
        }

        $jobs[(string) $backupId] = $job;
        $managerStore->setRuntimeOption('backup_upload_jobs', $jobs);
    }
}
