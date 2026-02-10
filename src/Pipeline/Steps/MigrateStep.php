<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class MigrateStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'migrate'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $this->shellRunner->runOrFail(['php', 'artisan', 'migrate', '--force']);
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['php', 'artisan', 'migrate:rollback', '--force']);
    }
}
