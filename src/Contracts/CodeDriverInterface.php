<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Contracts;

interface CodeDriverInterface
{
    public function currentRevision(): string;

    public function hasUpdates(): bool;

    public function update(): string;

    public function rollback(string $revision): void;
}
