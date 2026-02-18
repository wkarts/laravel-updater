<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\StateStore;

/**
 * Cria um backup "full" (DB + Snapshot) no mesmo padrão do backup manual.
 *
 * Observação:
 * - O run já armazena backup_file e snapshot_file separadamente.
 * - Este step adiciona também um item do tipo "full" em updater_backups (grid),
 *   empacotando os dois artefatos num único arquivo.
 */
class FullBackupStep implements PipelineStepInterface
{
    public function __construct(
        private readonly FileManager $fileManager,
        private readonly ArchiveManager $archiveManager,
        private readonly StateStore $store,
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

        $type = $this->resolveBackupType($context);

        // Criar "full" quando o tipo de backup pedir FULL.
        return in_array($type, ['full', 'full+snapshot', 'full+database'], true);
    }

    public function handle(array &$context): void
    {
        $db = (string) ($context['backup_file'] ?? '');
        $snapshot = (string) ($context['snapshot_file'] ?? '');

        if ($db === '' || $snapshot === '' || !is_file($db) || !is_file($snapshot)) {
            $context['full_backup_warning'] = 'Backup full não foi gerado porque DB e/ou Snapshot não estavam disponíveis.';
            return;
        }

        $compression = (string) ($context['options']['snapshot_compression'] ?? config('updater.snapshot.compression', 'zip'));
        if ($compression === 'auto') {
            // Mantém compatibilidade: ArchiveManager pode decidir. Aqui usamos zip como fallback.
            $compression = 'zip';
        }

        $backupPath = rtrim((string) config('updater.backup.path'), '/');
        $this->fileManager->ensureDirectory($backupPath);

        $fullBase = $backupPath . '/full_' . date('Ymd_His');

        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/updater-full-' . uniqid('', true);
        @mkdir($tmpDir, 0777, true);
        @mkdir($tmpDir . '/database', 0777, true);
        @mkdir($tmpDir . '/snapshot', 0777, true);

        @copy($db, $tmpDir . '/database/' . basename($db));
        @copy($snapshot, $tmpDir . '/snapshot/' . basename($snapshot));

        $fullPath = $this->archiveManager->createArchiveFromDirectory($tmpDir, $fullBase, $compression, []);

        // Best-effort cleanup
        $this->deleteDir($tmpDir);

        $runId = isset($context['run_id']) ? (int) $context['run_id'] : null;
        $profileId = isset($context['options']['profile_id']) ? (int) $context['options']['profile_id'] : null;

        // artifacts (opcional)
        $this->store->registerArtifact('full', $fullPath, ['run_id' => $runId]);

        // grid
        if ($fullPath !== '' && is_file($fullPath)) {
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

        $context['full_backup_file'] = $fullPath;
    }

    private function isPreUpdateBackupEnabled(): bool
    {
        return (bool) config('updater.backup.pre_update', true);
    }

    private function resolveBackupType(array $context): string
    {
        $raw = trim((string) ($context['options']['backup_type'] ?? config('updater.backup.pre_update_type', 'full')));
        $raw = strtolower(str_replace(' ', '', $raw));

        return match ($raw) {
            'snapshot', 'database', 'full+snapshot', 'full+database', 'full' => $raw,
            default => 'full',
        };
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function rollback(array &$context): void
    {
        // backup não precisa de rollback
    }
}
