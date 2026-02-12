<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Jobs\RunRollbackJob;
use Argws\LaravelUpdater\Jobs\RunUpdateJob;
use Symfony\Component\Process\Process;

class TriggerDispatcher
{
    public function __construct(private readonly string $driver, private readonly StateStore $store)
    {
    }

    public function triggerUpdate(array $options = []): void
    {
        if ($this->driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunUpdateJob($options));

            return;
        }

        $args = $this->buildUpdateCommandArgs($options);

        if ($this->driver === 'process' && class_exists(Process::class)) {
            $process = new Process($args);
            $process->disableOutput();
            $process->start();

            return;
        }

        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' > /dev/null 2>&1 &';
        exec($cmd);
    }

    public function triggerRollback(): void
    {
        if ($this->driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunRollbackJob());

            return;
        }

        if ($this->driver === 'process' && class_exists(Process::class)) {
            $process = new Process(['php', 'artisan', 'system:update:rollback', '--force']);
            $process->disableOutput();
            $process->start();

            return;
        }

        exec('php artisan system:update:rollback --force > /dev/null 2>&1 &');
    }

    private function buildUpdateCommandArgs(array $options): array
    {
        $args = ['php', 'artisan', 'system:update:run', '--force'];

        if ((bool) ($options['dry_run'] ?? false)) {
            $args[] = '--dry-run';
        }

        if ((bool) ($options['allow_dirty'] ?? false)) {
            $args[] = '--allow-dirty';
        }

        $seeders = $options['seeders'] ?? [];
        foreach ((array) $seeders as $seeder) {
            $args[] = '--seeder=' . (string) $seeder;
        }

        if ((bool) ($options['seed'] ?? false)) {
            $args[] = '--seed';
        }

        return $args;
    }
}
