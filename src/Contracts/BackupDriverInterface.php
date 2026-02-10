<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Contracts;

interface BackupDriverInterface
{
    public function backup(string $name): string;

    public function restore(string $filePath): void;
}
