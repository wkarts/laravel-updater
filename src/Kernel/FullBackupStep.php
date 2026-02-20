<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Kernel;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\StateStore;
use RuntimeException;

/**
 * FullBackupStep (OPCIONAL)
 *
 * Regra clara:
 * - Por padrão fica DESABILITADO para evitar backup duplicado.
 * - Quando habilitado, cria um único arquivo (ZIP/TGZ) do projeto para facilitar download/transferência.
 * - Habilitar via: config('updater.backup.create_full_archive') = true
 */
final class FullBackupStep implements PipelineStepInterface
{
    public function __construct(
        private readonly FileManager $files,
        private readonly ?ArchiveManager $archive,
        private readonly StateStore $store,
        private readonly bool $enabled = false,
    ) {
    }

    public function name(): string
    {
        return 'full_backup_archive';
    }

    /**
     * @param array<string,mixed> $context
     */
    public function shouldRun(array $context): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!empty($context['options']['dry_run'])) {
            return false;
        }

        if (!empty($context['options']['no_backup'])) {
            return false;
        }

        if ($this->archive === null) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function handle(array $context): array
    {
        if ($this->archive === null) {
            throw new RuntimeException('ArchiveManager não disponível para FullBackupStep.');
        }

        $projectRoot = base_path();
        $backupsDir = $this->store->backupsPath();

        $timestamp = date('Ymd_His');
        $ext = $this->archive->defaultExtension();
        $filename = sprintf('full_%s.%s', $timestamp, $ext);
        $target = rtrim($backupsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        // Evita zipar os próprios backups/snapshots e caches.
        $exclude = [
            'storage/app/updater',
            'storage/logs',
            'bootstrap/cache',
            '.git',
            '.idea',
            '.vscode',
            'node_modules',
        ];

        // Se o snapshot foi configurado para não incluir vendor, respeitamos aqui também.
        $includeVendor = (bool) ($context['options']['snapshot_include_vendor'] ?? config('updater.snapshot.include_vendor', false));
        if (!$includeVendor) {
            $exclude[] = 'vendor';
        }

        $this->files->ensureDirectoryExists(dirname($target));

        $this->archive->createFromDirectory(
            sourceDir: $projectRoot,
            targetFile: $target,
            exclude: $exclude,
        );

        $context['full_backup_file'] = $target;
        $context['files']['full_backup'] = $target;

        return $context;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function rollback(array $context): void
    {
        $file = (string) ($context['full_backup_file'] ?? '');
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }
}
