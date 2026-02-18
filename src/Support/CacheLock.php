<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Illuminate\Support\Facades\Cache;

class CacheLock implements LockInterface
{
    private mixed $lock = null;

    public function acquire(string $key, int $timeout): bool
    {
        $this->lock = Cache::lock($key, $timeout);

        // Aguarda até $timeout segundos para adquirir o lock (evita falha imediata quando há run em andamento).
        try {
            return $this->lock->block($timeout);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            $this->lock->release();
        }

        $this->lock = null;
    }

    public function isAcquired(): bool
    {
        return $this->lock !== null;
    }
}
