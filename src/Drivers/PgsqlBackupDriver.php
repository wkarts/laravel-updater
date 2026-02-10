<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Drivers;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Exceptions\BackupException;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ShellRunner;

class PgsqlBackupDriver implements BackupDriverInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly FileManager $files,
        private readonly array $dbConfig,
        private readonly array $backupConfig
    ) {
    }

    public function backup(string $name): string
    {
        $path = rtrim($this->backupConfig['path'], '/');
        $this->files->ensureDirectory($path);

        $file = $path . '/' . $name . '.dump';
        $cmd = sprintf('pg_dump --host=%s --port=%s --username=%s --dbname=%s --format=c --file=%s', escapeshellarg((string) $this->dbConfig['host']), escapeshellarg((string) $this->dbConfig['port']), escapeshellarg((string) $this->dbConfig['username']), escapeshellarg((string) $this->dbConfig['database']), escapeshellarg($file));
        $result = $this->shellRunner->run(['bash', '-lc', $cmd], null, ['PGPASSWORD' => (string) ($this->dbConfig['password'] ?? '')]);

        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao gerar backup PostgreSQL.');
        }

        if ((bool) ($this->backupConfig['compress'] ?? false)) {
            $this->shellRunner->runOrFail(['gzip', '-f', $file]);
            $file .= '.gz';
        }

        $this->files->deleteOldFiles($path, (int) ($this->backupConfig['keep'] ?? 10));

        return $file;
    }

    public function restore(string $filePath): void
    {
        $source = $filePath;
        if (str_ends_with($filePath, '.gz')) {
            $tmp = tempnam(sys_get_temp_dir(), 'updater_restore_') . '.dump';
            $this->shellRunner->runOrFail(['bash', '-lc', sprintf('gunzip -c %s > %s', escapeshellarg($filePath), escapeshellarg($tmp))]);
            $source = $tmp;
        }

        $cmd = sprintf('pg_restore --clean --if-exists --host=%s --port=%s --username=%s --dbname=%s %s', escapeshellarg((string) $this->dbConfig['host']), escapeshellarg((string) $this->dbConfig['port']), escapeshellarg((string) $this->dbConfig['username']), escapeshellarg((string) $this->dbConfig['database']), escapeshellarg($source));
        $result = $this->shellRunner->run(['bash', '-lc', $cmd], null, ['PGPASSWORD' => (string) ($this->dbConfig['password'] ?? '')]);

        if ($source !== $filePath) {
            @unlink($source);
        }

        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao restaurar backup PostgreSQL.');
        }
    }
}
