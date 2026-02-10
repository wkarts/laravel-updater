<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class ComposerInstallStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'composer_install'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $this->shellRunner->runOrFail(['composer', 'install', '--no-interaction', '--prefer-dist', '--optimize-autoloader']);
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['composer', 'install', '--no-interaction']);
    }
}
