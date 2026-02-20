<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Kernel;

use Argws\LaravelUpdater\Pipeline\StepInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\StateStore;

/**
 * FullBackupStep
 *
 * Gera um único arquivo (full_*.zip) contendo os artefatos já gerados no início do update:
 * - backup do banco (db_*.sql.gz)
 * - snapshot do código (snapshot_*.zip)
 *
 * IMPORTANTÍSSIMO:
 * - Este step é OPCIONAL e controlado por config('updater.backup.create_full_archive').
 * - Quando habilitado, ele NÃO cria backups extras do banco/código; apenas empacota os arquivos
 *   já produzidos pelos steps anteriores.
 * - Isso evita "backup duplicado" (gerar 2 fulls) e mantém rastreabilidade em rollback.
 */
final class FullBackupStep implements StepInterface
{
    public function __construct(
        private readonly FileManager $files,
        private readonly ?ArchiveManager $archive,
        private readonly StateStore $store,
        private readonly bool $enabled = false,
    ) {
    }

    public function key(): string
    {
        return 'full_backup';
    }

    public function shouldRun(array $context): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Só empacota se existir algo para empacotar.
        $backupFile = (string) ($context['backup_file'] ?? $this->store->get('backup_file', ''));
        $snapshotFile = (string) ($context['snapshot_file'] ?? $this->store->get('snapshot_file', ''));

        return $backupFile !== '' && $snapshotFile !== '' && is_file($backupFile) && is_file($snapshotFile);
    }

    public function handle(array $context): array
    {
        // Se não houver suporte a ZIP (ArchiveManager nulo), não quebra o update.
        if (!$this->archive instanceof ArchiveManager) {
            $context['full_backup_file'] = null;
            $this->store->set('full_backup_file', null);

            return $context;
        }

        $backupFile = (string) ($context['backup_file'] ?? $this->store->get('backup_file', ''));
        $snapshotFile = (string) ($context['snapshot_file'] ?? $this->store->get('snapshot_file', ''));

        // Diretório padrão de backups do updater.
        $dir = rtrim((string) config('updater.paths.backups', storage_path('app/updater/backups')), '/');
        $this->files->ensureDirectory($dir);

        $file = $dir . '/full_' . date('Ymd_His') . '.zip';

        // Empacota SOMENTE os artefatos gerados (db + snapshot).
        $this->archive->createZipFromFiles($file, [
            $backupFile,
            $snapshotFile,
        ]);

        $context['full_backup_file'] = $file;
        $this->store->set('full_backup_file', $file);

        return $context;
    }
}
