<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Illuminate\Support\Facades\Cache;

class CacheLock implements LockInterface
{
    private mixed $lock = null;
    private string $key = '';

    public function acquire(string $key, int $timeout): bool
    {
        $this->key = $key;
        $this->lock = Cache::lock($key, $timeout);

        // Em produção é comum haver concorrência (UI + job). Em vez de falhar instantaneamente,
        // esperamos alguns segundos. Se ainda falhar, tentamos detectar lock "stale" via meta.
        $wait = (int) config('updater.lock.block_seconds', 10);
        $wait = max(0, min($wait, $timeout));

        $acquired = $wait > 0 ? (bool) $this->lock->block($wait) : (bool) $this->lock->get();
        if ($acquired) {
            Cache::put($this->metaKey($key), [
                'pid' => getmypid(),
                'acquired_at' => time(),
            ], $timeout);

            return true;
        }

        $meta = Cache::get($this->metaKey($key));
        if (is_array($meta) && isset($meta['acquired_at']) && is_int($meta['acquired_at'])) {
            $age = time() - (int) $meta['acquired_at'];
            // Se o lock ficou mais velho do que 2x o TTL, assume stale e força limpeza.
            if ($age > max(60, $timeout * 2)) {
                Cache::forget($key);
                Cache::forget($this->metaKey($key));
                $this->lock = Cache::lock($key, $timeout);
                $acquired = $wait > 0 ? (bool) $this->lock->block($wait) : (bool) $this->lock->get();
                if ($acquired) {
                    Cache::put($this->metaKey($key), [
                        'pid' => getmypid(),
                        'acquired_at' => time(),
                    ], $timeout);
                    return true;
                }
            }
        }

        return false;
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            $this->lock->release();
        }

        if ($this->key !== '') {
            Cache::forget($this->metaKey($this->key));
        }

        $this->lock = null;
        $this->key = '';
    }

    public function isAcquired(): bool
    {
        return $this->lock !== null;
    }

    private function metaKey(string $key): string
    {
        return $key . ':meta';
    }
}
