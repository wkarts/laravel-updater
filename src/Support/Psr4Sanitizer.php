<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class Psr4Sanitizer
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function run(string $projectPath, array $config = []): array
    {
        $mode = (string) ($config['mode'] ?? 'quarantine');
        if ($mode === 'off') {
            return [
                'mode' => 'off',
                'checked_files' => 0,
                'findings' => [],
                'quarantined_files' => [],
                'deleted_files' => [],
                'warnings' => [],
                'blockers' => [],
            ];
        }

        $paths = $this->normalizePaths($projectPath, (array) ($config['paths'] ?? [$projectPath . '/app']));
        $filenamePatterns = (array) ($config['filename_patterns'] ?? []);
        $directoryNamePatterns = array_map('mb_strtolower', (array) ($config['directory_name_patterns'] ?? []));
        $quarantinePath = $this->resolvePath($projectPath, (string) ($config['quarantine_path'] ?? ($projectPath . '/storage/app/updater/quarantine/psr4')));

        $checkedFiles = 0;
        $findings = [];
        $warnings = [];
        $blockers = [];
        $quarantinedFiles = [];
        $deletedFiles = [];
        $fqcnMap = [];

        foreach ($paths as $rootPath) {
            if (!is_dir($rootPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if (mb_strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $checkedFiles++;
                $absolutePath = $file->getPathname();
                $relativePath = $this->relativePath($projectPath, $absolutePath);
                $filename = $file->getFilename();

                $finding = $this->matchSuspiciousFilename($filename, $filenamePatterns);
                if ($finding !== null) {
                    $findings[] = [
                        'severity' => 'safe_quarantine',
                        'reason' => $finding,
                        'path' => $relativePath,
                    ];

                    if ($mode === 'quarantine') {
                        $quarantinedFiles[] = $this->quarantineFile($absolutePath, $relativePath, $quarantinePath);
                    }

                    if ($mode === 'delete') {
                        @unlink($absolutePath);
                        $deletedFiles[] = $relativePath;
                    }

                    continue;
                }

                $pathSegments = array_values(array_filter(explode('/', str_replace('\\', '/', dirname($relativePath)))));
                $suspiciousDir = $this->matchSuspiciousDirectory($pathSegments, $directoryNamePatterns);
                if ($suspiciousDir !== null) {
                    $findings[] = [
                        'severity' => 'safe_quarantine',
                        'reason' => 'directory_pattern:' . $suspiciousDir,
                        'path' => $relativePath,
                    ];

                    if ($mode === 'quarantine') {
                        $quarantinedFiles[] = $this->quarantineFile($absolutePath, $relativePath, $quarantinePath);
                    }

                    if ($mode === 'delete') {
                        @unlink($absolutePath);
                        $deletedFiles[] = $relativePath;
                    }

                    continue;
                }

                $analysis = $this->analyzePhpSymbol($absolutePath);
                if ($analysis === null) {
                    continue;
                }

                $declaredName = (string) ($analysis['symbol'] ?? '');
                $declaredNamespace = (string) ($analysis['namespace'] ?? '');
                $fileStem = pathinfo($filename, PATHINFO_FILENAME);
                if ($declaredName !== '' && $declaredName !== $fileStem) {
                    $warnings[] = [
                        'severity' => 'warn_only',
                        'reason' => 'symbol_name_mismatch',
                        'path' => $relativePath,
                        'symbol' => $declaredName,
                        'expected' => $fileStem,
                    ];
                }

                if ($declaredName !== '') {
                    $fqcn = ltrim(($declaredNamespace !== '' ? $declaredNamespace . '\\' : '') . $declaredName, '\\');
                    if ($fqcn !== '') {
                        $fqcnMap[$fqcn] = $fqcnMap[$fqcn] ?? [];
                        $fqcnMap[$fqcn][] = $relativePath;
                    }
                }
            }
        }

        foreach ($fqcnMap as $fqcn => $pathsForClass) {
            if (count($pathsForClass) <= 1) {
                continue;
            }

            $blockers[] = [
                'severity' => 'blocker',
                'reason' => 'duplicate_symbol',
                'symbol' => $fqcn,
                'paths' => $pathsForClass,
            ];
        }

        return [
            'mode' => $mode,
            'checked_files' => $checkedFiles,
            'findings' => $findings,
            'quarantined_files' => $quarantinedFiles,
            'deleted_files' => $deletedFiles,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    /** @param array<int,string> $patterns */
    private function matchSuspiciousFilename(string $filename, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if (@preg_match($pattern, $filename) === 1) {
                return 'filename_pattern:' . $pattern;
            }
        }

        return null;
    }

    /** @param array<int,string> $pathSegments @param array<int,string> $patterns */
    private function matchSuspiciousDirectory(array $pathSegments, array $patterns): ?string
    {
        foreach ($pathSegments as $segment) {
            if (in_array(mb_strtolower($segment), $patterns, true)) {
                return $segment;
            }
        }

        return null;
    }

    /** @return array{namespace:string,symbol:string}|null */
    private function analyzePhpSymbol(string $filePath): ?array
    {
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return null;
        }

        $tokens = token_get_all($content);
        $namespace = '';
        $symbol = '';

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                $i++;
                while ($i < $count) {
                    $t = $tokens[$i];
                    if (is_string($t) && ($t === ';' || $t === '{')) {
                        break;
                    }

                    if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) {
                        $namespace .= $t[1];
                    }

                    $i++;
                }
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $i++;
                while ($i < $count) {
                    $t = $tokens[$i];
                    if (is_array($t) && $t[0] === T_STRING) {
                        $symbol = $t[1];
                        break 2;
                    }

                    $i++;
                }
            }
        }

        if ($symbol === '') {
            return null;
        }

        return [
            'namespace' => $namespace,
            'symbol' => $symbol,
        ];
    }

    private function quarantineFile(string $absolutePath, string $relativePath, string $quarantinePath): string
    {
        $source = realpath($absolutePath);
        if ($source === false || !is_file($source)) {
            return $relativePath;
        }

        $destination = rtrim($quarantinePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . date('Ymd_His')
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

        $directory = dirname($destination);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar diretório de quarentena: ' . $directory);
        }

        if (!@rename($source, $destination)) {
            throw new RuntimeException('Não foi possível mover arquivo para quarentena: ' . $relativePath);
        }

        return $this->relativePath(dirname(dirname(dirname($quarantinePath))), $destination);
    }

    /** @param array<int,string> $paths @return array<int,string> */
    private function normalizePaths(string $projectPath, array $paths): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }

            $resolved[] = $this->resolvePath($projectPath, $path);
        }

        return array_values(array_unique($resolved));
    }

    private function resolvePath(string $projectPath, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return rtrim($projectPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function relativePath(string $basePath, string $absolutePath): string
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/');
        $absolute = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($absolute, $base . '/')) {
            return substr($absolute, strlen($base) + 1);
        }

        return ltrim($absolute, '/');
    }
}
