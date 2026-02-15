<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Exceptions\UpdaterException;

class PreflightChecker
{
    public function __construct(
        private readonly ShellRunner $shell,
        private readonly CodeDriverInterface $git,
        private readonly array $config
    ) {
    }

    public function report(array $options = []): array
    {
        $report = ['binaries' => [], 'paths' => [], 'disk' => [], 'git_clean' => true];

        $binaries = ['git', 'php', 'composer', 'tar', 'gzip'];
        foreach ($binaries as $binary) {
            $report['binaries'][$binary] = $this->shell->binaryExists($binary);
        }

        $requireClean = (bool) ($this->config['require_clean_git'] ?? true);
        $allowDirty = (bool) ($options['allow_dirty'] ?? false);
        // Compatibilidade com config publicada antiga (sem allow_dirty_updates):
        // padrão permissivo para não bloquear update por working tree dirty.
        $allowDirtyByDefault = (bool) ($this->config['allow_dirty_updates'] ?? true);
        $report['git_clean'] = !$requireClean || $allowDirty || $allowDirtyByDefault || $this->git->isWorkingTreeClean();

        $minFree = (int) ($this->config['min_free_disk_mb'] ?? 200);
        $free = (int) floor((disk_free_space(base_path()) ?: 0) / 1024 / 1024);
        $report['disk'] = ['free_mb' => $free, 'min_mb' => $minFree, 'ok' => $free >= $minFree];

        foreach ([config('updater.backup.path'), config('updater.snapshot.path'), dirname((string) config('updater.sqlite.path'))] as $path) {
            $report['paths'][] = ['path' => (string) $path, 'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname((string) $path))];
        }

        return $report;
    }

    public function validate(array $options = []): void
    {
        $binaries = ['git', 'php', 'composer', 'tar', 'gzip'];
        foreach ($binaries as $binary) {
            if (!$this->shell->binaryExists($binary)) {
                throw new UpdaterException("Binário obrigatório não encontrado: {$binary}");
            }
        }

        $dbDriver = config('database.connections.' . config('database.default') . '.driver');
        if ($dbDriver === 'mysql' && !$this->shell->binaryExists('mysqldump')) {
            throw new UpdaterException('Binário mysqldump não encontrado.');
        }
        if ($dbDriver === 'pgsql' && !$this->shell->binaryExists('pg_dump')) {
            throw new UpdaterException('Binário pg_dump não encontrado.');
        }

        $requireClean = (bool) ($this->config['require_clean_git'] ?? true);
        $allowDirty = (bool) ($options['allow_dirty'] ?? false);
        // Mesmo fallback no validate() para evitar quebra em projetos com config legado.
        $allowDirtyByDefault = (bool) ($this->config['allow_dirty_updates'] ?? true);
        if ($requireClean && !$allowDirty && !$allowDirtyByDefault && !$this->git->isWorkingTreeClean()) {
            throw new UpdaterException('Repositório git possui alterações locais (dirty). Use --allow-dirty para sobrescrever esta validação.');
        }

        $minFree = (int) ($this->config['min_free_disk_mb'] ?? 200);
        $free = (int) floor((disk_free_space(base_path()) ?: 0) / 1024 / 1024);
        if ($free < $minFree) {
            throw new UpdaterException("Espaço em disco insuficiente: {$free}MB disponível, mínimo {$minFree}MB.");
        }

        foreach ([config('updater.backup.path'), config('updater.snapshot.path'), dirname((string) config('updater.sqlite.path'))] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                throw new UpdaterException("Sem permissão de escrita em: {$path}");
            }
        }
    }
}