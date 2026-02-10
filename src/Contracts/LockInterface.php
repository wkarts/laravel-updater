<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Contracts;

interface LockInterface
{
    public function acquire(string $key, int $timeout): bool;

    public function release(): void;

    public function isAcquired(): bool;
}
