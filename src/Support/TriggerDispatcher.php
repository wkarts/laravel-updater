<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Jobs\RunRollbackJob;
use Argws\LaravelUpdater\Jobs\RunUpdateJob;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Symfony\Component\Process\Process;

class TriggerDispatcher
{
    public function __construct(
        private readonly string $driver,
        private readonly StateStore $store,
        private readonly UpdateRecoveryManager $recovery
    ) {
    }

    public function triggerUpdate(array $options = []): ?int
    {
        // Antes de bloquear uma nova atualização, reconcilia runs órfãs deixadas
        // por fatal error, OOM, reboot ou processo executor encerrado.
        $this->recovery->reconcile();

        if ($this->store->hasActiveRun()) {
            $activeId = $this->store->activeRunId();
            throw new \RuntimeException(
                'Já existe uma execução em andamento' . ($activeId ? ' (run #' . $activeId . ')' : '') . '.'
            );
        }

        $forceSync = (bool) ($options['sync'] ?? false);
        $driver = ($forceSync || (bool) ($options['dry_run'] ?? false)) ? 'sync' : $this->resolveDriver();

        // Atualização REAL iniciada pela UI nunca pode depender de queue=sync,
        // FPM ou do ciclo de vida da requisição. Mesmo que o administrador tenha
        // configurado trigger=queue/sync, a UI força executor destacado no SO.
        if ((bool) ($options['allow_http'] ?? false) && !(bool) ($options['dry_run'] ?? false)) {
            $driver = $this->detachedUiDriver();
        }

        $options['dispatch_driver'] = $driver;
        $options['dispatch_sapi'] = PHP_SAPI;
        $options['queue_connection'] = $this->queueConnectionName();
        $options['queue_driver'] = $this->queueConnectionDriver();

        $modernCommandAvailable = $this->isUpdateCommandAvailable();

        // A UI só pode iniciar a implementação moderna, que aceita --run-id e
        // persiste PID/heartbeat. O comando legado não possui contrato suficiente
        // para recuperação segura e, portanto, não pode ser usado por HTTP.
        if ((bool) ($options['allow_http'] ?? false) && !$modernCommandAvailable) {
            throw new \RuntimeException(
                'Executor CLI moderno do Laravel Updater indisponível. O update não será executado dentro da requisição HTTP '
                . 'nem será encaminhado ao comando legado. Valide "php artisan system:update:run --help".'
            );
        }

        if (!$modernCommandAvailable && !$this->isLegacyUpdateCommandAvailable()) {
            if ($driver !== 'sync') {
                throw new \RuntimeException(
                    'Executor CLI do Laravel Updater indisponível. Valide "php artisan system:update:run --help".'
                );
            }

            return $this->runUpdateInline($options);
        }

        $runId = $this->store->createQueuedRun($options);
        $options['run_id'] = $runId;

        try {
            if ($driver === 'queue' && function_exists('dispatch')) {
                $this->store->setRunWorker($runId, null, 'queue');
                dispatch(new RunUpdateJob($options));

                return $runId;
            }

            $args = $this->buildUpdateCommandArgs($options);

            if ($driver === 'sync') {
                $this->store->setRunWorker($runId, getmypid() ?: null, 'sync-dispatch');

                if (class_exists(Process::class)) {
                    $process = new Process($args, $this->resolveProjectBasePath());
                    $process->setTimeout(null);
                    $process->run();

                    if (!$process->isSuccessful()) {
                        $output = trim(($process->getErrorOutput() ?: '') . "\n" . ($process->getOutput() ?: ''));
                        throw new \RuntimeException('Falha ao executar atualização: ' . $output);
                    }
                } else {
                    $output = [];
                    $exitCode = 0;
                    exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);
                    if ((int) $exitCode !== 0) {
                        throw new \RuntimeException('Falha ao executar atualização em modo sync. ' . trim(implode("\n", $output)));
                    }
                }

                return $runId;
            }

            // Atualização real: processo destacado no servidor. A resposta HTTP pode
            // terminar, o navegador pode fechar e o update continua normalmente.
            $pid = $this->spawnBackground($args);
            $this->store->setRunWorker($runId, $pid, $driver);

            return $runId;
        } catch (\Throwable $e) {
            $run = $this->store->findRun($runId);
            if (is_array($run) && in_array((string) ($run['status'] ?? ''), ['queued', 'running'], true)) {
                $this->store->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);
                $this->store->addRunLog($runId, 'error', 'Falha ao iniciar executor da atualização.', [
                    'erro' => $e->getMessage(),
                    'driver' => $driver,
                ]);
            }

            throw $e;
        }
    }

    private function resolveUpdateCommandName(): string
    {
        // Preferencial: comando namespaced (novo)
        if ($this->isUpdateCommandAvailable()) {
            return 'system:update:run';
        }

        // Fallback: comando legado sem namespace
        return 'system:update';
    }

    private function isLegacyUpdateCommandAvailable(): bool
    {
        $args = ['php', 'artisan', 'system:update', '--help'];

        if (class_exists(Process::class)) {
            $process = new Process($args, $this->resolveProjectBasePath(), $this->probeEnv());
            $process->setTimeout(20);
            $process->run();

            return $process->isSuccessful();
        }

        exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);

        return (int) $exitCode === 0;
    }

    private function isAnyUpdateCommandAvailable(): bool
    {
        return $this->isUpdateCommandAvailable() || $this->isLegacyUpdateCommandAvailable();
    }


    public function triggerManualBackup(string $type, int $runId): array
    {
        $args = ['php', 'artisan', 'system:update:backup', '--type=' . $type, '--run-id=' . $runId];
        $this->assertManualBackupCommandAvailable();
        $driver = $this->resolveDriver();

        if ($driver === 'sync') {
            if (class_exists(Process::class)) {
                $process = new Process($args, $this->resolveProjectBasePath());
                $process->setTimeout(null);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Falha ao executar backup manual: ' . ($process->getErrorOutput() ?: $process->getOutput()));
                }

                return ['started' => true, 'pid' => null];
            }

            exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);
            if ((int) $exitCode !== 0) {
                throw new \RuntimeException('Falha ao executar backup manual em modo sync.');
            }

            return ['started' => true, 'pid' => null];
        }

        $pid = $this->spawnBackground($args);

        return ['started' => true, 'pid' => $pid];
    }


    public function triggerBackupUpload(int $backupId): array
    {
        $args = ['php', 'artisan', 'system:update:backup-upload', '--backup-id=' . $backupId];
        $this->assertBackupUploadCommandAvailable();
        $driver = $this->resolveDriver();

        if ($driver === 'sync') {
            if (class_exists(Process::class)) {
                $process = new Process($args, $this->resolveProjectBasePath());
                $process->setTimeout(null);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Falha ao executar upload de backup: ' . ($process->getErrorOutput() ?: $process->getOutput()));
                }

                return ['started' => true, 'pid' => null];
            }

            exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);
            if ((int) $exitCode !== 0) {
                throw new \RuntimeException('Falha ao executar upload de backup em modo sync.');
            }

            return ['started' => true, 'pid' => null];
        }

        $pid = $this->spawnBackground($args);

        return ['started' => true, 'pid' => $pid];
    }

    public function triggerRollback(): void
    {
        $this->recovery->reconcile();

        if ($this->store->hasActiveRun()) {
            throw new \RuntimeException("Já existe uma execução em andamento.");
        }
        $driver = $this->resolveDriver();
        if ($driver === 'queue' && function_exists('dispatch')) {
            dispatch(new RunRollbackJob());

            return;
        }

        $args = ['php', 'artisan', 'system:update:rollback', '--force'];

        if ($driver === 'sync') {
            if (class_exists(Process::class)) {
                $process = new Process($args, $this->resolveProjectBasePath());
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
            $process = new Process($args, $this->resolveProjectBasePath());
            $process->disableOutput();
            $process->start();

            return;
        }

        if ($this->isWindows()) {
            if (class_exists(Process::class)) {
                $process = new Process($args, $this->resolveProjectBasePath());
                $process->disableOutput();
                $process->start();
            }

            return;
        }

        exec('php artisan system:update:rollback --force > /dev/null 2>&1 &');
    }



    private function assertManualBackupCommandAvailable(): void
    {
        $args = ['php', 'artisan', 'system:update:backup', '--help'];
        if (class_exists(Process::class)) {
            $process = new Process($args, $this->resolveProjectBasePath());
            $process->setTimeout(20);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput() . "\n" . $process->getOutput()));
            }

            return;
        }

        exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);
        if ((int) $exitCode !== 0) {
            throw new \RuntimeException(trim(implode("\n", $output)));
        }
    }


    private function assertBackupUploadCommandAvailable(): void
    {
        $args = ['php', 'artisan', 'system:update:backup-upload', '--help'];
        if (class_exists(Process::class)) {
            $process = new Process($args, $this->resolveProjectBasePath());
            $process->setTimeout(20);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput() . "\n" . $process->getOutput()));
            }

            return;
        }

        exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);
        if ((int) $exitCode !== 0) {
            throw new \RuntimeException(trim(implode("\n", $output)));
        }
    }


    private function runUpdateInline(array $options): ?int
    {
        if (!function_exists('app') || !class_exists(UpdaterKernel::class)) {
            throw new \RuntimeException('Comando system:update:run indisponível e fallback inline não pôde ser executado.');
        }

        $before = (int) (($this->store->lastRun()['id'] ?? 0));
        /** @var UpdaterKernel $kernel */
        $kernel = app(UpdaterKernel::class);
        $kernel->run($options);
        $after = (int) (($this->store->lastRun()['id'] ?? 0));

        return $after > $before ? $after : null;
    }

    private function isUpdateCommandAvailable(): bool
    {
        $args = ['php', 'artisan', 'system:update:run', '--help'];

        if (class_exists(Process::class)) {
            $process = new Process($args, $this->resolveProjectBasePath(), $this->probeEnv());
            $process->setTimeout(20);
            $process->run();

            return $process->isSuccessful();
        }

        exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exitCode);

        return (int) $exitCode === 0;
    }

    private function detachedUiDriver(): string
    {
        return $this->isWindows() ? 'process' : 'background';
    }

    private function queueConnectionName(): ?string
    {
        if (!function_exists('config')) {
            return null;
        }

        try {
            $name = trim((string) config('queue.default', ''));
            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function queueConnectionDriver(): ?string
    {
        $connection = $this->queueConnectionName();
        if ($connection === null || !function_exists('config')) {
            return null;
        }

        try {
            $driver = trim((string) config('queue.connections.' . $connection . '.driver', ''));
            return $driver !== '' ? strtolower($driver) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveDriver(): string
    {
        $configured = strtolower(trim($this->driver));
        if ($configured === '' || $configured === 'auto') {
            // Auto should never freeze the UI. On Windows, prefer non-blocking execution.
            return $this->isWindows() ? 'process' : 'background';
        }

        return $configured;
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Spawns a background process.
     *
     * @param array<int,string> $args
     */
    private function spawnBackground(array $args): ?int
    {
        // Unix: destaque real do processo no SO. Não mantém pipes ligados ao
        // PHP-FPM/request e devolve o PID do executor.
        if (!$this->isWindows()) {
            $basePath = $this->resolveProjectBasePath();
            $command = 'cd ' . escapeshellarg($basePath)
                . ' && nohup ' . implode(' ', array_map('escapeshellarg', $args))
                . ' > /dev/null 2>&1 < /dev/null & echo $!';

            $pid = trim((string) @shell_exec($command));
            if ($pid !== '' && ctype_digit($pid)) {
                return (int) $pid;
            }

            // Ambientes com shell_exec desabilitado ainda podem usar Symfony Process.
            if (class_exists(Process::class)) {
                $process = new Process($args, $basePath);
                $process->disableOutput();
                $process->start();

                return $process->getPid() ?: null;
            }

            throw new \RuntimeException('Não foi possível destacar o executor de atualização no servidor.');
        }

        if (class_exists(Process::class)) {
            $process = new Process($args, $this->resolveProjectBasePath());
            $process->disableOutput();
            $process->start();

            return $process->getPid() ?: null;
        }

        $cmd = $this->buildWindowsDetachedCommand($args);
        @pclose(@popen($cmd, 'r'));

        return null;
    }

    /**
     * @param array<int,string> $args
     */
    private function buildWindowsDetachedCommand(array $args): string
    {
        $escaped = array_map(static fn ($a) => '"' . str_replace('"', '\\"', (string) $a) . '"', $args);
        $joined = implode(' ', $escaped);

        return 'cmd /C start "" /B ' . $joined;
    }

    private function buildUpdateCommandArgs(array $options): array
    {
        $args = ['php', 'artisan', $this->resolveUpdateCommandName(), '--force'];

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

        if (!empty($options['run_id'])) {
            $args[] = '--run-id=' . (int) $options['run_id'];
        }

        // Não propaga --allow-http para o executor filho. O processo destacado
        // deve executar obrigatoriamente como CLI; allow_http só identifica a origem
        // da solicitação no dispatcher web.
        if ((bool) ($options['replay_migrations_from_start'] ?? false)) {
            $args[] = '--replay-migrations-from-start';
        }

        $seeders = $options['seeders'] ?? [];
        foreach ((array) $seeders as $seeder) {
            $args[] = '--seeder=' . (string) $seeder;
        }

        if ((bool) ($options['seed'] ?? false)) {
            $args[] = '--seed';
        }

        foreach ((array) ($options['pre_update_commands'] ?? []) as $cmd) {
            $line = trim((string) $cmd);
            if ($line === '') {
                continue;
            }
            $args[] = '--pre-command=' . $line;
        }

        foreach ((array) ($options['post_update_commands'] ?? []) as $cmd) {
            $line = trim((string) $cmd);
            if ($line === '') {
                continue;
            }
            $args[] = '--post-command=' . $line;
        }

        return $args;
    }

    /**
     * Resolve a safe project base path.
     *
     * Motivação:
     * - O helper global base_path() depende de app()->basePath().
     * - Em cenários de testes/unit do pacote, o "app()" pode ser apenas um Container
     *   simples (sem basePath), causando erro fatal.
     *
     * Regra:
     * - Se houver Application com basePath(), usa.
     * - Caso contrário, tenta APP_BASE_PATH.
     * - Por fim, cai para getcwd() (mais previsível em CI).
     */
    

    /**
     * Ambiente seguro para "probes" (checagens rápidas) via `php artisan ... --help`.
     * Evita poluir storage/logs/laravel.log quando o comando não existe ou o bootstrap falha.
     *
     * @return array<string,string>
     */
    private function probeEnv(): array
    {
        $merged = [];
        foreach ([$_SERVER ?? [], $_ENV ?? []] as $src) {
            if (!is_array($src)) {
                continue;
            }
            foreach ($src as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (is_string($v) || is_numeric($v)) {
                    $merged[$k] = (string) $v;
                }
            }
        }

        // Direciona logs do Laravel para STDERR durante probes (não grava em arquivo).
        $merged['LOG_CHANNEL'] = 'stderr';
        $merged['APP_DEBUG'] = 'false';
        $merged['UPDATER_INTERNAL_PROBE'] = '1';

        return $merged;
    }

private function resolveProjectBasePath(): string
    {
        try {
            if (function_exists('app')) {
                $app = app();
                if (is_object($app) && method_exists($app, 'basePath')) {
                    $path = (string) $app->basePath();
                    if (trim($path) !== '') {
                        return rtrim($path, DIRECTORY_SEPARATOR);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignora e faz fallback
        }

        $env = (string) (getenv('APP_BASE_PATH') ?: ($_SERVER['APP_BASE_PATH'] ?? ''));
        if (trim($env) !== '') {
            return rtrim($env, DIRECTORY_SEPARATOR);
        }

        return (string) (getcwd() ?: '.');
    }
}
