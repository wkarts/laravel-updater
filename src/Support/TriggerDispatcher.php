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


    public function webTriggerDiagnostic(?string $sourceType = null): array
    {
        if (!(bool) config('updater.ui.allow_web_trigger', true)) {
            return ['ok' => false, 'reason' => 'Execução web desabilitada por configuração (UPDATER_UI_ALLOW_WEB_TRIGGER=false).'];
        }

        if ($this->driver === 'queue') {
            $connection = (string) config('queue.default', 'sync');
            $queueDriver = (string) config("queue.connections.{$connection}.driver", $connection);
            if ($queueDriver === 'sync') {
                return ['ok' => false, 'reason' => 'Driver de fila atual é "sync"; a execução ocorreria no request HTTP e o updater exige CLI. Configure fila assíncrona ou mude UPDATER_TRIGGER_DRIVER para process.'];
            }
        }

        if ($sourceType !== null && $sourceType !== '' && $this->requiresGitRepository($sourceType) && !$this->isGitRepository()) {
            return ['ok' => false, 'reason' => 'A fonte ativa exige repositório Git, mas o diretório atual não possui .git válido.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    public function triggerUpdate(array $options = []): void
    {
        $forceSync = (bool) ($options['sync'] ?? false);
        $driver = ($forceSync || (bool) ($options['dry_run'] ?? false)) ? 'sync' : $this->resolveDriver();

        if ($driver === 'queue' && function_exists('dispatch')) {
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

        return null;
    }

    public function triggerRollback(): void
    {
        $driver = $this->resolveDriver();
        if ($driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunRollbackJob());

            return;
        }

        if ($driver === 'process' && class_exists(Process::class)) {
            $process = new Process($args, base_path());
            $process->disableOutput();
            $process->start();

            return;
        }

        exec('php artisan system:update:rollback --force > /dev/null 2>&1 &');
    }

    private function requiresGitRepository(string $sourceType): bool
    {
        $type = strtolower($sourceType);

        return $type !== 'zip_release';
    }

    private function isGitRepository(): bool
    {
        $result = (new ShellRunner())->run(['git', 'rev-parse', '--is-inside-work-tree']);

        return $result['exit_code'] === 0 && trim((string) $result['stdout']) === 'true';
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
