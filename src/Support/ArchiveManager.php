<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class ArchiveManager
{
    /** @param array<int,string> $excludePaths */
    public function createZipFromDirectory(string $sourceDir, string $targetZip, array $excludePaths = []): void
    {
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

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $fullPath = $this->normalizePath((string) $file->getPathname());
            if (!str_starts_with($fullPath, $sourceDir . '/')) {
                continue;
            }

            $relativePath = ltrim(substr($fullPath, strlen($sourceDir)), '/');

            if ($relativePath === '' || $this->shouldSkip($relativePath, $exclude)) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
                continue;
            }

            $zip->addFile($fullPath, $relativePath);
        }

        $zip->close();
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

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Formato de backup não suportado para restore automático.');
        }

        if (!$zip->extractTo($destination)) {
            $zip->close();
            throw new RuntimeException('Falha ao extrair backup compactado.');
        }

        $zip->close();
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
