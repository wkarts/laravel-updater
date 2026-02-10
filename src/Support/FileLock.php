<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Contracts\LockInterface;

class FileLock implements LockInterface
{
    private mixed $handle = null;

    public function __construct(private readonly string $lockFile)
    {
    }

    public function acquire(string $key, int $timeout): bool
    {
        $this->handle = fopen($this->lockFile . '.' . md5($key), 'c+');

        if (!is_resource($this->handle)) {
            return false;
        }

        $start = time();
        do {
            if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                ftruncate($this->handle, 0);
                fwrite($this->handle, (string) getmypid());
                return true;
            }
            usleep(100_000);
        } while ((time() - $start) < $timeout);

        return false;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function isAcquired(): bool
    {
        return is_resource($this->handle);
    }
}
