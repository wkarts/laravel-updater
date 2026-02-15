<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

class PostUpdateCommandsStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'post_update_commands'; }

    public function shouldRun(array $context): bool
    {
        return !empty($context['options']['post_update_commands']);
    }

    public function handle(array &$context): void
    {
        $commands = array_values(array_filter((array) ($context['options']['post_update_commands'] ?? [])));

        foreach ($commands as $command) {
            $line = trim((string) $command);
            if ($line === '') {
                continue;
            }

            if ((bool) ($context['options']['dry_run'] ?? false)) {
                $context['dry_run_plan']['post_update_commands'][] = $line;
                continue;
            }

            $this->shellRunner->runOrFail(['bash', '-lc', $line]);
            $context['post_update_commands_log'][] = $line . ': executado';
        }
    }

    public function rollback(array &$context): void
    {
    }
}
