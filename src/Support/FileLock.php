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
        $path = $this->lockFile . '.' . md5($key);
        $this->handle = fopen($path, 'c+');

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

        // Se não conseguiu adquirir dentro do timeout, tenta detectar lock "stale".
        // Isso ocorre quando um processo travou/morreu e deixou o lock preso.
        $pid = $this->readPid();
        $stale = $pid !== null ? !$this->isProcessAlive($pid) : $this->isFileTooOld($path, $timeout);

        if ($stale) {
            // Libera handle e tenta remover o arquivo para que uma próxima tentativa consiga lock.
            $this->safeClose();
            @unlink($path);
            $this->handle = fopen($path, 'c+');
            if (is_resource($this->handle) && flock($this->handle, LOCK_EX | LOCK_NB)) {
                ftruncate($this->handle, 0);
                fwrite($this->handle, (string) getmypid());
                return true;
            }
        }

        return false;
    }

    public function release(): void
    {
        $this->safeClose();
    }

    public function isAcquired(): bool
    {
        return is_resource($this->handle);
    }

    private function safeClose(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    private function readPid(): ?int
    {
        if (!is_resource($this->handle)) {
            return null;
        }

        @rewind($this->handle);
        $raw = trim((string) @stream_get_contents($this->handle));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function isProcessAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return true;
    }

    private function isFileTooOld(string $path, int $timeout): bool
    {
        $mtime = @filemtime($path);
        if (!is_int($mtime) || $mtime <= 0) {
            return false;
        }

        return (time() - $mtime) > max(60, ($timeout * 2));
    }
}
