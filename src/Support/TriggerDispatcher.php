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
        $this->store->createRun(array_merge($options, ['triggered_via' => 'http']));

        if ($this->driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunUpdateJob($options));
            return;
        }

        if ($this->driver === 'process' && class_exists(Process::class)) {
            $process = new Process(['php', 'artisan', 'system:update:run', '--force']);
            $process->disableOutput();
            $process->start();
            return;
        }

        $cmd = 'php artisan system:update:run --force > /dev/null 2>&1 &';
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
}
