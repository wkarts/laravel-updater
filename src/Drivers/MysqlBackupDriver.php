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
        $path = rtrim((string) $this->backupConfig['path'], '/\\');
        $this->files->ensureDirectory($path);

        $file = $path . DIRECTORY_SEPARATOR . $name . '.sql';
        $binary = $this->resolveBinary('mysqldump');

        $args = [
            $binary,
            '--host=' . (string) ($this->dbConfig['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($this->dbConfig['port'] ?? '3306'),
            '--user=' . (string) ($this->dbConfig['username'] ?? ''),
            '--result-file=' . $file,
            (string) ($this->dbConfig['database'] ?? ''),
        ];

        $env = [];
        if (!empty($this->dbConfig['password'])) {
            $env['MYSQL_PWD'] = (string) $this->dbConfig['password'];
        }

        $result = $this->shellRunner->run($args, null, $env);

        if ($result['exit_code'] !== 0 || !is_file($file)) {
            throw new BackupException($result['stderr'] ?: 'Falha ao gerar backup MySQL. Verifique caminho do mysqldump (UPDATER_MYSQLDUMP_BINARY).');
        }

        if ((bool) ($this->backupConfig['compress'] ?? false) && function_exists('gzencode')) {
            $compressed = $file . '.gz';
            $content = file_get_contents($file);
            if ($content !== false) {
                file_put_contents($compressed, gzencode($content, 9));
                @unlink($file);
                $file = $compressed;
            }
        }

        $this->files->deleteOldFiles($path, (int) ($this->backupConfig['keep'] ?? 10));

        return $file;
    }

    public function restore(string $filePath): void
    {
        $source = $filePath;
        if (str_ends_with($filePath, '.gz')) {
            $tmp = tempnam(sys_get_temp_dir(), 'updater_restore_');
            if ($tmp === false) {
                throw new BackupException('Falha ao criar arquivo temporário de restore.');
            }

            $raw = file_get_contents($filePath);
            $decoded = $raw !== false ? gzdecode($raw) : false;
            if ($decoded === false) {
                throw new BackupException('Falha ao descompactar backup MySQL.');
            }
            file_put_contents($tmp, $decoded);
            $source = $tmp;
        }

        $binary = $this->resolveBinary('mysql');
        $args = [
            $binary,
            '--host=' . (string) ($this->dbConfig['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($this->dbConfig['port'] ?? '3306'),
            '--user=' . (string) ($this->dbConfig['username'] ?? ''),
            '--execute=source ' . $source,
            (string) ($this->dbConfig['database'] ?? ''),
        ];

        $env = [];
        if (!empty($this->dbConfig['password'])) {
            $env['MYSQL_PWD'] = (string) $this->dbConfig['password'];
        }

        $result = $this->shellRunner->run($args, null, $env);

        if ($source !== $filePath) {
            @unlink($source);
        }

        if ($result['exit_code'] !== 0) {
            throw new BackupException($result['stderr'] ?: 'Falha ao restaurar backup MySQL.');
        }
    }

    private function resolveBinary(string $default): string
    {
        $custom = (string) config('updater.backup.' . $default . '_binary', '');
        if ($custom !== '') {
            return $custom;
        }

        $candidates = [$default];
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = $default . '.exe';
        }

        foreach ($candidates as $candidate) {
            if ($this->shellRunner->binaryExists($candidate)) {
                return $candidate;
            }
        }

        return $default;
    }
}
