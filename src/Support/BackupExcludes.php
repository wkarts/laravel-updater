<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class BackupExcludes
{
    /** @return array<int,string> */
    public static function snapshot(
        bool $includeVendor,
        bool $excludeStorage,
        bool $excludeUploads = false,
        ?array $baseExcludes = null,
        ?array $uploadsPaths = null
    ): array
    {
        $excludes = $baseExcludes ?? (array) config('updater.paths.exclude_snapshot', []);
        $excludes[] = '.git';
        $excludes[] = '.git/';
        $excludes[] = 'storage/app/updater';
        $excludes[] = 'storage/framework/down';

        if ($excludeStorage) {
            $excludes[] = 'storage';
            $excludes[] = 'storage/';
        }

        if (!$includeVendor) {
            $excludes[] = 'vendor';
        }

        if (!$excludeUploads) {
            $uploads = $uploadsPaths ?? (array) config('updater.paths.uploads_paths', ['public/uploads']);
            $excludes = self::removeUploadPaths($excludes, $uploads);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item, '/'),
            $excludes
        ), static fn (string $item): bool => $item !== '')));
    }

    /** @param array<int,string> $excludes @param array<int,string> $uploadsPaths @return array<int,string> */
    private static function removeUploadPaths(array $excludes, array $uploadsPaths): array
    {
        $normalizedUploads = array_values(array_filter(array_map(static fn (mixed $path): string => trim((string) $path, '/'), $uploadsPaths), static fn (string $path): bool => $path !== ''));
        if ($normalizedUploads === []) {
            return $excludes;
        }

        return array_values(array_filter($excludes, static function (mixed $item) use ($normalizedUploads): bool {
            $candidate = trim((string) $item, '/');
            if ($candidate === '') {
                return false;
            }

            return !in_array($candidate, $normalizedUploads, true);
        }));
    }
}
