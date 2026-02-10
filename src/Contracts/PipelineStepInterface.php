<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Contracts;

interface PipelineStepInterface
{
    public function name(): string;

    public function shouldRun(array $context): bool;

    public function handle(array &$context): void;

    public function rollback(array &$context): void;
}
