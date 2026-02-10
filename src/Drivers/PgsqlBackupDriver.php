<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Drivers;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Exceptions\BackupException;
use Argws\LaravelUpdater\Support\ShellRunner;

class PgsqlBackupDriver implements BackupDriverInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly array $dbConfig, private readonly array $backupConfig)
    {
    }

    public function backup(string $name): string
    {
        $file = rtrim($this->backupConfig['path'], '/') . '/' . $name . '.sql';
        $command = sprintf(
            "PGPASSWORD=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s --format=p > %s",
            escapeshellarg((string) $this->dbConfig['password']),
            escapeshellarg((string) $this->dbConfig['host']),
            escapeshellarg((string) $this->dbConfig['port']),
            escapeshellarg((string) $this->dbConfig['username']),
            escapeshellarg((string) $this->dbConfig['database']),
            escapeshellarg($file)
        );

        $result = $this->shellRunner->run(['bash', '-lc', $command]);
        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao gerar backup PostgreSQL.');
        }

        return $file;
    }

    public function restore(string $filePath): void
    {
        $command = sprintf(
            "PGPASSWORD=%s psql --host=%s --port=%s --username=%s --dbname=%s < %s",
            escapeshellarg((string) $this->dbConfig['password']),
            escapeshellarg((string) $this->dbConfig['host']),
            escapeshellarg((string) $this->dbConfig['port']),
            escapeshellarg((string) $this->dbConfig['username']),
            escapeshellarg((string) $this->dbConfig['database']),
            escapeshellarg($filePath)
        );

        $result = $this->shellRunner->run(['bash', '-lc', $command]);
        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao restaurar backup PostgreSQL.');
        }
    }
}
