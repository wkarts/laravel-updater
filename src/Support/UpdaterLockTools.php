<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Support\Facades\Cache;

class UpdaterLockTools
{
    public function info(string $key = 'system-update'): array
    {
        $driver = (string) config('updater.lock.driver', 'file');

        if ($driver === 'cache') {
            $meta = Cache::get($this->metaKey($key));
            return [
                'driver' => 'cache',
                'key' => $key,
                'meta' => is_array($meta) ? $meta : null,
            ];
        }

        $path = storage_path('framework/cache/updater.lock') . '.' . md5($key);
        $pid = null;
        if (is_file($path)) {
            $raw = trim((string) @file_get_contents($path));
            if ($raw !== '' && ctype_digit($raw)) {
                $pid = (int) $raw;
            }
        }

        return [
            'driver' => 'file',
            'key' => $key,
            'path' => $path,
            'pid' => $pid,
            'mtime' => is_file($path) ? @filemtime($path) : null,
        ];
    }

    public function forceClear(string $key = 'system-update'): void
    {
        $driver = (string) config('updater.lock.driver', 'file');

        if ($driver === 'cache') {
            Cache::forget($key);
            Cache::forget($this->metaKey($key));
            return;
        }

        $path = storage_path('framework/cache/updater.lock') . '.' . md5($key);
        @unlink($path);
    }

    private function metaKey(string $key): string
    {
        return $key . ':meta';
    }
}
