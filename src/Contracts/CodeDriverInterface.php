<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Contracts;

interface CodeDriverInterface
{
    public function currentRevision(): string;

    public function hasUpdates(): bool;

    /** @return array{local:string,remote:string,behind_by_commits:int,ahead_by_commits:int,has_updates:bool} */
    public function statusUpdates(): array;

    public function isWorkingTreeClean(): bool;

    public function update(): string;

    public function rollback(string $revision): void;
}
