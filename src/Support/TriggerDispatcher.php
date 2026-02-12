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

    public function triggerUpdate(array $options = []): ?int
    {
        $forceSync = (bool) ($options['sync'] ?? false);
        $driver = ($forceSync || (bool) ($options['dry_run'] ?? false)) ? 'sync' : $this->resolveDriver();

        if ($driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunUpdateJob($options));

            return null;
        }

        $args = $this->buildUpdateCommandArgs($options);

        if ($driver === 'sync') {
            $before = (int) (($this->store->lastRun()['id'] ?? 0));
            if (class_exists(Process::class)) {
                $process = new Process($args, base_path());
                $process->setTimeout(null);
                $process->run();

                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Falha ao executar atualização: ' . ($process->getErrorOutput() ?: $process->getOutput()));
                }
            } else {
                exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);
                if ((int) $exitCode !== 0) {
                    throw new \RuntimeException('Falha ao executar atualização em modo sync.');
                }
            }

            $after = (int) (($this->store->lastRun()['id'] ?? 0));

            return $after > $before ? $after : null;
        }

        if ($driver === 'process' && class_exists(Process::class)) {
            $process = new Process($args, base_path());
            $process->disableOutput();
            $process->start();

            return null;
        }

        if ($this->isWindows()) {
            if (class_exists(Process::class)) {
                $process = new Process($args, base_path());
                $process->disableOutput();
                $process->start();
            }

            return null;
        }

        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' > /dev/null 2>&1 &';
        exec($cmd);

        return null;
    }

    public function triggerRollback(): void
    {
        $driver = $this->resolveDriver();
        if ($driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunRollbackJob());

            return;
        }

        $args = ['php', 'artisan', 'system:update:rollback', '--force'];

        if ($driver === 'sync') {
            if (class_exists(Process::class)) {
                $process = new Process($args, base_path());
                $process->setTimeout(null);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Falha ao executar rollback: ' . ($process->getErrorOutput() ?: $process->getOutput()));
                }

                return;
            }

            exec('php artisan system:update:rollback --force');

            return;
        }

        if ($driver === 'process' && class_exists(Process::class)) {
            $process = new Process($args, base_path());
            $process->disableOutput();
            $process->start();

            return;
        }

        if ($this->isWindows()) {
            if (class_exists(Process::class)) {
                $process = new Process($args, base_path());
                $process->disableOutput();
                $process->start();
            }

            return;
        }

        exec('php artisan system:update:rollback --force > /dev/null 2>&1 &');
    }

    private function resolveDriver(): string
    {
        $configured = strtolower(trim($this->driver));
        if ($configured === '' || $configured === 'auto') {
            if ($this->isWindows()) {
                return 'sync';
            }

            return 'background';
        }

        if ($this->isWindows() && in_array($configured, ['background', 'process'], true)) {
            return 'sync';
        }

        return $configured;
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
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

        if (!empty($options['update_type'])) {
            $args[] = '--update-type=' . (string) $options['update_type'];
        }

        if (!empty($options['target_tag'])) {
            $args[] = '--tag=' . (string) $options['target_tag'];
        }

        if (!empty($options['source_id'])) {
            $args[] = '--source-id=' . (int) $options['source_id'];
        }

        if (!empty($options['profile_id'])) {
            $args[] = '--profile-id=' . (int) $options['profile_id'];
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
