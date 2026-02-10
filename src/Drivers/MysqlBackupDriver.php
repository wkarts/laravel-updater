<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Drivers;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Exceptions\BackupException;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ShellRunner;

class MysqlBackupDriver implements BackupDriverInterface
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

        $file = $path . '/' . $name . '.sql';
        $cnf = tempnam(sys_get_temp_dir(), 'updater_mysql_');
        if ($cnf === false) {
            throw new BackupException('Falha ao criar arquivo temporário de credenciais MySQL.');
        }

        file_put_contents($cnf, "[client]\nuser={$this->dbConfig['username']}\npassword={$this->dbConfig['password']}\nhost={$this->dbConfig['host']}\nport={$this->dbConfig['port']}\n");
        chmod($cnf, 0600);

        $command = sprintf('mysqldump --defaults-extra-file=%s %s > %s', escapeshellarg($cnf), escapeshellarg((string) $this->dbConfig['database']), escapeshellarg($file));
        $result = $this->shellRunner->run(['bash', '-lc', $command]);
        @unlink($cnf);

        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao gerar backup MySQL.');
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
            $tmp = tempnam(sys_get_temp_dir(), 'updater_restore_') . '.sql';
            $this->shellRunner->runOrFail(['bash', '-lc', sprintf('gunzip -c %s > %s', escapeshellarg($filePath), escapeshellarg($tmp))]);
            $source = $tmp;
        }

        $cnf = tempnam(sys_get_temp_dir(), 'updater_mysql_');
        file_put_contents($cnf, "[client]\nuser={$this->dbConfig['username']}\npassword={$this->dbConfig['password']}\nhost={$this->dbConfig['host']}\nport={$this->dbConfig['port']}\n");
        chmod($cnf, 0600);

        $command = sprintf('mysql --defaults-extra-file=%s %s < %s', escapeshellarg($cnf), escapeshellarg((string) $this->dbConfig['database']), escapeshellarg($source));
        $result = $this->shellRunner->run(['bash', '-lc', $command]);

        @unlink($cnf);
        if ($source !== $filePath) {
            @unlink($source);
        }

        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao restaurar backup MySQL.');
        }
    }
}
