<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class ArchiveManager
{
    /** @param array<int,string> $excludePaths */
    public function createArchiveFromDirectory(string $sourceDir, string $targetBasePath, string $format = 'auto', array $excludePaths = []): string
    {
        $this->disableTimeLimit();
        $resolved = $this->resolveFormat($format);

        // Blindagem anti-recursão:
        // Se o arquivo de saída estiver dentro do diretório de origem (ex.: snapshots em storage/app/updater),
        // a compactação pode incluir o próprio arquivo em crescimento e explodir de tamanho.
        // Portanto, sempre excluímos o diretório de saída relativo ao sourceDir.
        $source = rtrim($this->normalizePath($sourceDir), '/');
        $targetBase = $this->normalizePath($targetBasePath);
        $targetDir = rtrim($this->normalizePath(dirname($targetBase)), '/');
        if ($targetDir !== '' && str_starts_with($targetDir, $source . '/')) {
            $relativeTargetDir = ltrim(substr($targetDir, strlen($source)), '/');
            if ($relativeTargetDir !== '') {
                $excludePaths[] = $relativeTargetDir;
            }
        }

        // Nunca permitir que o diretório operacional do updater entre em snapshot/full.
        // Isso evita loops (snapshots dentro de snapshots), além de reduzir peso e exposição.
        $excludePaths[] = 'storage/app/updater';
        $excludePaths[] = 'storage/framework/down';

        return match ($resolved) {
            '7z' => $this->create7zFromDirectory($sourceDir, $targetBasePath . '.7z', $excludePaths),
            'tgz' => $this->createTgzFromDirectory($sourceDir, $targetBasePath . '.tar.gz', $excludePaths),
            default => $this->createZipFromDirectory($sourceDir, $targetBasePath . '.zip', $excludePaths),
        };
    }

    /** @param array<int,string> $excludePaths */
    public function createZipFromDirectory(string $sourceDir, string $targetZip, array $excludePaths = []): string
    {
        $this->disableTimeLimit();
        $exclude = array_map([$this, 'normalizePath'], $excludePaths);
        $sourceDir = rtrim($this->normalizePath($sourceDir), '/');

        $dir = dirname($targetZip);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($targetZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar arquivo de backup compactado.');
        }

        $i = 0;
        foreach ($this->collectFiles($sourceDir, $exclude) as [$fullPath, $relativePath]) {
            $zip->addFile($fullPath, $relativePath);
            if ((++$i % 400) === 0) {
                $this->touchTimeLimit();
            }
        }

        $zip->close();

        return $targetZip;
    }

    public function createZipFromFiles(array $files, string $targetZip): void
    {
        $dir = dirname($targetZip);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($targetZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar arquivo ZIP.');
        }

        foreach ($files as $filePath => $entryName) {
            if (!is_file((string) $filePath)) {
                continue;
            }

            $zip->addFile((string) $filePath, (string) $entryName);
        }

        $zip->close();
    }

    public function extractArchive(string $archivePath, string $destination): void
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('Arquivo de backup não encontrado para restauração.');
        }

        $lower = strtolower($archivePath);

        if (str_ends_with($lower, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('Formato ZIP inválido para restore automático.');
            }
            if (!$zip->extractTo($destination)) {
                $zip->close();
                throw new RuntimeException('Falha ao extrair backup ZIP.');
            }
            $zip->close();

            return;
        }

        if (str_ends_with($lower, '.7z')) {
            $this->extract7z($archivePath, $destination);

            return;
        }

        if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
            $this->extractTgz($archivePath, $destination);

            return;
        }

        if (str_ends_with($lower, '.tar')) {
            $phar = new PharData($archivePath);
            $phar->extractTo($destination, null, true);

            return;
        }

        throw new RuntimeException('Formato de backup não suportado para restore automático.');
    }

    /** @param array<int,string> $excludePaths */
    private function createTgzFromDirectory(string $sourceDir, string $targetTgz, array $excludePaths = []): string
    {
        $this->disableTimeLimit();
        $sourceDir = rtrim($this->normalizePath($sourceDir), '/');
        $exclude = array_map([$this, 'normalizePath'], $excludePaths);

        $dir = dirname($targetTgz);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tarPath = preg_replace('/(\.tar\.gz|\.tgz)$/i', '.tar', $targetTgz) ?: ($targetTgz . '.tar');
        @unlink($tarPath);
        @unlink($targetTgz);

        $phar = new PharData($tarPath);
        $i = 0;
        foreach ($this->collectFiles($sourceDir, $exclude) as [$fullPath, $relativePath]) {
            $phar->addFile($fullPath, $relativePath);
            if ((++$i % 250) === 0) {
                $this->touchTimeLimit();
            }
        }

        $phar->compress(\Phar::GZ);
        unset($phar);

        $compressed = $tarPath . '.gz';
        if (!is_file($compressed)) {
            throw new RuntimeException('Falha ao gerar arquivo TGZ.');
        }

        @unlink($targetTgz);
        @rename($compressed, $targetTgz);
        @unlink($tarPath);

        return $targetTgz;
    }

    /** @param array<int,string> $excludePaths */
    private function create7zFromDirectory(string $sourceDir, string $target7z, array $excludePaths = []): string
    {
        if (!$this->has7zBinary()) {
            return $this->createZipFromDirectory($sourceDir, preg_replace('/\.7z$/i', '.zip', $target7z) ?: ($target7z . '.zip'), $excludePaths);
        }

        $sourceDir = rtrim($this->normalizePath($sourceDir), '/');
        $exclude = array_map([$this, 'normalizePath'], $excludePaths);

        $dir = dirname($target7z);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @unlink($target7z);

        $files = [];
        foreach ($this->collectFiles($sourceDir, $exclude) as [, $relativePath]) {
            $files[] = $relativePath;
        }

        if ($files === []) {
            throw new RuntimeException('Nenhum arquivo encontrado para compactação 7z.');
        }

        $listFile = tempnam(sys_get_temp_dir(), 'updater-7z-list-');
        if (!is_string($listFile) || $listFile === '') {
            throw new RuntimeException('Falha ao preparar lista de arquivos para 7z.');
        }

        file_put_contents($listFile, implode(PHP_EOL, $files));

        $cmd = $this->isWindows()
            ? '7z a -t7z -mx=5 ' . escapeshellarg($target7z) . ' @' . escapeshellarg($listFile)
            : '7z a -t7z -mx=5 ' . escapeshellarg($target7z) . ' @' . escapeshellarg($listFile);

        $output = [];
        $exit = 0;
        exec('cd ' . escapeshellarg($sourceDir) . ' && ' . $cmd . ' 2>&1', $output, $exit);
        @unlink($listFile);

        if ($exit !== 0 || !is_file($target7z)) {
            throw new RuntimeException('Falha ao gerar arquivo 7z: ' . implode("\n", $output));
        }

        return $target7z;
    }

    private function extractTgz(string $archivePath, string $destination): void
    {
        $tarPath = preg_replace('/(\.tar\.gz|\.tgz)$/i', '.tar', $archivePath) ?: ($archivePath . '.tar');
        @unlink($tarPath);

        $phar = new PharData($archivePath);
        $phar->decompress();

        $tar = new PharData($tarPath);
        $tar->extractTo($destination, null, true);
        @unlink($tarPath);
    }

    private function extract7z(string $archivePath, string $destination): void
    {
        if (!$this->has7zBinary()) {
            throw new RuntimeException('Binary 7z não encontrado para extração.');
        }

        $output = [];
        $exit = 0;
        $cmd = '7z x -y ' . escapeshellarg($archivePath) . ' -o' . escapeshellarg($destination);
        exec($cmd . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException('Falha ao extrair 7z: ' . implode("\n", $output));
        }
    }

    private function resolveFormat(string $format): string
    {
        $format = strtolower(trim($format));

        if ($format === 'zip') {
            return 'zip';
        }

        // Força ZIP por padrão para compatibilidade entre ambientes Linux/Windows.
        return 'zip';
    }

    private function has7zBinary(): bool
    {
        $cmd = $this->isWindows() ? 'where 7z' : 'command -v 7z';
        $result = @shell_exec($cmd . ' 2>/dev/null');

        return is_string($result) && trim($result) !== '';
    }

    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /** @return array<int,array{0:string,1:string}> */
    private function collectFiles(string $sourceDir, array $exclude): array
    {
        $items = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $fullPath = $this->normalizePath((string) $file->getPathname());
            if (!str_starts_with($fullPath, $sourceDir . '/')) {
                continue;
            }

            $relativePath = ltrim(substr($fullPath, strlen($sourceDir)), '/');
            if ($relativePath === '' || $this->shouldSkip($relativePath, $exclude)) {
                continue;
            }

            $items[] = [$fullPath, $relativePath];
        }

        return $items;
    }


    private function disableTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    private function touchTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    private function shouldSkip(string $relativePath, array $exclude): bool
    {
        $normalized = $this->normalizePath($relativePath);
        foreach ($exclude as $item) {
            $item = trim($item, '/');
            if ($item === '') {
                continue;
            }

            if ($normalized === $item || str_starts_with($normalized, $item . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
