<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Illuminate\Support\Facades\Cache;

/**
 * Detecta e recupera execuções órfãs do updater.
 *
 * Uma execução não pode bloquear a UI indefinidamente após OOM, fatal error,
 * encerramento do worker, reinício do servidor ou falha ao destacar o processo.
 */
class UpdateRecoveryManager
{
    /** @var array<int,bool> */
    private array $fatalGuards = [];

    private ?string $reservedMemory = null;

    public function __construct(
        private readonly StateStore $store,
        private readonly UpdaterLockTools $lockTools,
        private readonly ShellRunner $shell
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $this->store->ensureSchema();
        $run = $this->store->activeRun();

        if ($run === null) {
            return [
                'active' => false,
                'recoverable' => false,
                'run' => null,
                'reason' => null,
                'age_seconds' => 0,
                'process_alive' => null,
            ];
        }

        $status = (string) ($run['status'] ?? 'running');
        $pid = (int) ($run['worker_pid'] ?? 0);
        $heartbeat = trim((string) ($run['heartbeat_at'] ?? ''));
        $startedAt = trim((string) ($run['started_at'] ?? ''));
        $reference = $heartbeat !== '' ? $heartbeat : $startedAt;
        $timestamp = $reference !== '' ? strtotime($reference) : false;
        $age = $timestamp !== false ? max(0, time() - $timestamp) : 0;
        $alive = $pid > 0 ? $this->isProcessAlive($pid, (int) ($run['id'] ?? 0)) : null;

        $runningStaleAfter = max(60, (int) config('updater.recovery.stale_after_seconds', 900));
        $queuedStaleAfter = max(30, (int) config('updater.recovery.queued_stale_after_seconds', 120));

        $recoverable = false;
        $reason = null;

        if ($pid > 0 && $alive === false) {
            $recoverable = true;
            $reason = 'Processo executor não está mais ativo.';
        } elseif ($pid <= 0 && $status === 'queued' && $age >= $queuedStaleAfter) {
            $recoverable = true;
            $reason = 'Execução permaneceu na fila sem iniciar dentro do limite esperado.';
        } elseif ($pid <= 0 && $status === 'running' && $age >= $runningStaleAfter) {
            // Compatibilidade com runs antigas, criadas antes de worker_pid/heartbeat.
            $recoverable = true;
            $reason = 'Execução antiga sem PID/heartbeat ativo excedeu o limite de segurança.';
        }

        return [
            'active' => true,
            'recoverable' => $recoverable,
            'run' => $run,
            'reason' => $reason,
            'age_seconds' => $age,
            'process_alive' => $alive,
        ];
    }

    /**
     * Faz recuperação automática somente quando há evidência de execução órfã.
     *
     * @return array<string,mixed>
     */
    public function reconcile(): array
    {
        $state = $this->inspect();

        if (!(bool) config('updater.recovery.enabled', true)
            || !(bool) config('updater.recovery.auto_recover', true)
            || !($state['active'] ?? false)
            || !($state['recoverable'] ?? false)) {
            return $state;
        }

        $runId = (int) (($state['run']['id'] ?? 0));
        if ($runId <= 0) {
            return $state;
        }

        $reason = (string) ($state['reason'] ?? 'Execução órfã detectada.');
        $this->recoverRun($runId, 'Recuperação automática: ' . $reason);

        $after = $this->inspect();
        $after['recovered_run_id'] = $runId;
        $after['recovery_message'] = $reason;

        return $after;
    }

    public function recoverActive(string $reason = 'Recuperação manual solicitada pela UI.', bool $force = false): bool
    {
        $state = $this->inspect();
        if (!($state['active'] ?? false)) {
            $this->cleanupOperationalState(true);
            return false;
        }

        if (!$force && !($state['recoverable'] ?? false)) {
            return false;
        }

        $runId = (int) (($state['run']['id'] ?? 0));
        if ($runId <= 0) {
            return false;
        }

        $this->recoverRun($runId, $reason);

        return true;
    }

    public function recoverRun(int $runId, string $reason): void
    {
        try {
            $run = $this->store->findRun($runId);
            if (is_array($run) && in_array((string) ($run['status'] ?? ''), ['queued', 'running'], true)) {
                $this->store->updateRunStatus($runId, 'failed', [
                    'message' => $reason,
                    'recovered' => true,
                ]);

                $this->store->addRunLog($runId, 'error', 'Execução interrompida foi recuperada automaticamente.', [
                    'motivo' => $reason,
                ]);
            }
        } finally {
            $this->cleanupOperationalState(true);
        }
    }

    public function registerFatalGuard(int $runId): void
    {
        if (!(bool) config('updater.recovery.shutdown_guard', true) || $runId <= 0) {
            return;
        }

        $this->fatalGuards[$runId] = true;

        if ($this->reservedMemory === null) {
            $kb = max(512, min(8192, (int) config('updater.recovery.reserve_memory_kb', 2048)));
            $this->reservedMemory = str_repeat('R', $kb * 1024);
        }

        register_shutdown_function(function () use ($runId): void {
            if (!($this->fatalGuards[$runId] ?? false)) {
                return;
            }

            $error = error_get_last();
            if (!is_array($error) || !$this->isFatalError((int) ($error['type'] ?? 0))) {
                return;
            }

            // Libera a reserva antes de qualquer tentativa de recuperação.
            $this->reservedMemory = null;

            $message = trim((string) ($error['message'] ?? 'Fatal error'));
            $file = trim((string) ($error['file'] ?? ''));
            $line = (int) ($error['line'] ?? 0);
            $reason = 'Fatal error durante atualização: ' . $message;
            if ($file !== '') {
                $reason .= ' em ' . $file . ($line > 0 ? ':' . $line : '');
            }

            try {
                $run = $this->store->findRun($runId);
                if (is_array($run) && in_array((string) ($run['status'] ?? ''), ['queued', 'running'], true)) {
                    $this->store->updateRunStatus($runId, 'failed', [
                        'message' => $reason,
                        'fatal' => true,
                        'recovered' => true,
                    ]);
                    $this->store->addRunLog($runId, 'error', 'Fatal error detectado pelo guard de recuperação.', [
                        'erro' => $message,
                        'arquivo' => $file,
                        'linha' => $line,
                    ]);
                }
            } catch (\Throwable) {
                // A limpeza operacional abaixo ainda deve ser tentada.
            }

            try {
                $this->cleanupOperationalState(false);
            } catch (\Throwable) {
                // Shutdown handler nunca deve gerar uma segunda falha fatal.
            }
        });
    }

    public function disarmFatalGuard(int $runId): void
    {
        unset($this->fatalGuards[$runId]);

        if ($this->fatalGuards === []) {
            $this->reservedMemory = null;
        }
    }

    private function cleanupOperationalState(bool $runArtisanUp): void
    {
        try {
            Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);
        } catch (\Throwable) {
            // best effort
        }

        try {
            $this->lockTools->forceClear('system-update');
        } catch (\Throwable) {
            // best effort
        }

        if ($runArtisanUp) {
            try {
                $this->shell->run(['php', 'artisan', 'up']);
            } catch (\Throwable) {
                // fallback abaixo remove diretamente o marcador nativo.
            }
        }

        if (function_exists('storage_path')) {
            @unlink(storage_path('framework/down'));
        }
    }

    private function isFatalError(int $type): bool
    {
        return in_array($type, [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ], true);
    }

    private function isProcessAlive(int $pid, int $runId = 0): ?bool
    {
        if ($pid <= 0) {
            return null;
        }

        if ($pid === getmypid()) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            if (function_exists('posix_kill') && !@posix_kill($pid, 0)) {
                return false;
            }

            // Linux: /proc é mais confiável que depender da extensão posix.
            $procDir = '/proc/' . $pid;
            if (is_dir('/proc')) {
                if (!is_dir($procDir)) {
                    return false;
                }

                $cmdlinePath = $procDir . '/cmdline';
                if (is_readable($cmdlinePath)) {
                    $cmdline = @file_get_contents($cmdlinePath);
                    if (is_string($cmdline) && $cmdline !== '') {
                        $cmdline = str_replace("\0", ' ', $cmdline);

                        // Para executores modernos, o PID deve continuar pertencendo
                        // ao artisan do updater e ao run correspondente. Isso evita
                        // falso positivo quando o SO recicla um PID antigo.
                        if (str_contains($cmdline, 'system:update:run')) {
                            if ($runId <= 0 || str_contains($cmdline, '--run-id=' . $runId)) {
                                return true;
                            }

                            return false;
                        }

                        return false;
                    }
                }

                // Existe em /proc, mas cmdline pode ser inacessível por política do SO.
                return true;
            }

            if (function_exists('exec')) {
                $output = [];
                $exit = 1;
                @exec('kill -0 ' . (int) $pid . ' 2>/dev/null', $output, $exit);

                return $exit === 0;
            }

            return function_exists('posix_kill') ? true : null;
        }

        if (function_exists('shell_exec')) {
            $output = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
            if (is_string($output)) {
                $normalized = strtolower($output);
                if (str_contains($normalized, 'no tasks are running')
                    || str_contains($normalized, 'nenhuma tarefa')
                    || str_contains($normalized, 'informação:')) {
                    return false;
                }

                return str_contains($output, (string) $pid);
            }
        }

        return null;
    }
}
