<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Kernel;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\UpdaterPaths;

/**
 * FullBackupStep (compatibilidade)
 *
 * Regras IMPORTANTES (não remover):
 * - Esta etapa existe porque algumas versões do UpdaterKernel referenciam
 *   explicitamente Argws\LaravelUpdater\Kernel\FullBackupStep.
 * - O sistema NÃO deve gerar backups duplicados.
 *   Se um full backup já foi gerado nesta execução (context['full_backup_file']),
 *   esta etapa deve ser ignorada.
 * - Por padrão, o full backup EXCLUI vendor para não duplicar com snapshot/db.
 *   Caso precise incluir vendor, usar context['full_backup_include_vendor']=true
 *   (ou mapear isso a partir do perfil/config antes de rodar a pipeline).
 */
class FullBackupStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shell,
        private readonly UpdaterPaths $paths,
    ) {
    }

    public function name(): string
    {
        return 'full_backup';
    }

    public function shouldRun(array $context): bool
    {
        // no_backup desativa TODOS os backups (db/snapshot/full)
        if ((bool) ($context['no_backup'] ?? false)) {
            return false;
        }

        // permite desativar full backup explicitamente
        if ((bool) ($context['full_backup_enabled'] ?? true) === false) {
            return false;
        }

        // evita duplicidade: já existe full_backup_file definido
        if (is_string($context['full_backup_file'] ?? null) && trim((string) $context['full_backup_file']) !== '') {
            return false;
        }

        return true;
    }

    public function handle(array &$context): void
    {
        $timestamp = date('Ymd_His');
        $fullFile = $this->paths->backupPath("full_{$timestamp}.zip");

        $root = rtrim($this->paths->basePath(), '/');

        // Exclui diretórios pesados/voláteis e segredos.
        // (O objetivo é um "snapshot do código" amplo, mas sem duplicar o que já existe
        // em outras etapas e sem explodir o storage.)
        $exclude = [
            '.git',
            'node_modules',
            'storage/logs',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
        ];

        $includeVendor = (bool) ($context['full_backup_include_vendor'] ?? false);
        if (!$includeVendor) {
            $exclude[] = 'vendor';
        }

        $excludeArgs = [];
        foreach ($exclude as $path) {
            $excludeArgs[] = "-x '{$path}/*'";
        }

        $cmd = "cd '{$root}' && zip -r '{$fullFile}' . " . implode(' ', $excludeArgs);
        $this->shell->run($cmd, allowFail: false);

        $context['full_backup_file'] = $fullFile;
    }

    public function shouldRollback(array $context): bool
    {
        return is_string($context['full_backup_file'] ?? null) && trim((string) $context['full_backup_file']) !== '';
    }

    public function rollback(array &$context): void
    {
        $file = trim((string) ($context['full_backup_file'] ?? ''));
        if ($file !== '' && file_exists($file)) {
            @unlink($file);
        }
    }
}
