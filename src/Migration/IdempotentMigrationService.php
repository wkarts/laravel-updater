<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Migration;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

class IdempotentMigrationService
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly MigrationFailureClassifier $classifier,
        private readonly MigrationReconciler $reconciler,
        private readonly MigrationDriftDetector $driftDetector
    ) {
    }

    public function run(array $options, MigrationRunReporter $reporter): array
    {
        $isEnabled = (bool) ($options['idempotent'] ?? true);
        $mode = (string) ($options['mode'] ?? 'tolerant');
        $strict = $mode === 'strict' || (bool) ($options['strict'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $replayFromStart = (bool) ($options['replay_from_start'] ?? false);
        $reconcileAlreadyExists = (bool) ($options['reconcile_already_exists'] ?? false);
        $shouldReconcileAlreadyExists = $reconcileAlreadyExists || $replayFromStart;
        $lockRetries = max(0, (int) ($options['retry_locks'] ?? 2));
        $retrySleepBase = max(1, (int) ($options['retry_sleep_base'] ?? 3));

        $repository = $this->migrator->getRepository();
        if (!$repository->repositoryExists()) {
            $repository->createRepository();
        }

        $paths = $this->resolvePaths($options);
        $connection = $options['database'] ?? null;
        if (is_string($connection) && $connection !== '') {
            $this->migrator->setConnection($connection);
        }

        $allFiles = $this->migrator->getMigrationFiles($paths);
        $ran = array_flip($repository->getRan());
        $pending = $replayFromStart
            ? $allFiles
            : array_filter(
                $allFiles,
                static fn (string $path, string $name): bool => !array_key_exists($name, $ran),
                ARRAY_FILTER_USE_BOTH
            );

        $stats = [
            'total' => count($pending),
            'executed' => 0,
            'reconciled' => 0,
            'retried' => 0,
            'failed' => 0,
            'warnings' => 0,
            'skipped_dry_run' => 0,
            'skipped_runtime_warning' => 0,
            'divergences' => [],
        ];

        $runId = $reporter->runId();

        $reporter->log('info', 'Iniciando updater:migrate.', [
            'mode' => $mode,
            'dry_run' => $dryRun,
            'idempotent' => $isEnabled,
            'replay_from_start' => $replayFromStart,
            'reconcile_already_exists' => $shouldReconcileAlreadyExists,
            'pending' => array_keys($pending),
            'connection' => $connection,
        ]);

        foreach ($pending as $name => $path) {
            try {
                $drift = $this->driftDetector->inspect($path, is_string($connection) ? $connection : null);
            } catch (Throwable $throwable) {
                $drift = ['action' => 'run', 'reason' => 'drift_detector_error', 'object' => null];
                $stats['warnings']++;
                $stats['divergences'][] = [
                    'migration' => $name,
                    'type' => 'DRIFT_DETECTOR_ERROR',
                    'object' => null,
                    'note' => mb_substr($throwable->getMessage(), 0, 500),
                ];
                $reporter->log('warning', 'Falha no detector de drift; seguindo migration em modo seguro.', [
                    'migration' => $name,
                    'path' => $path,
                    'error' => $throwable->getMessage(),
                ]);
            }

            if ($dryRun) {
                $stats['skipped_dry_run']++;
                $reporter->log('info', 'Dry-run de migration.', ['migration' => $name, 'path' => $path, 'simulation' => $drift]);
                $reporter->migrationAttempt($name, 'dry_run', 1, $path, $replayFromStart, false, true, null, ['simulation' => $drift], 'updater:migrate');
                continue;
            }

            $wasLogged = array_key_exists($name, $ran);
            $deletedForReplay = false;

            if ($replayFromStart && $wasLogged) {
                $repository->delete((object) ['migration' => $name]);
                $deletedForReplay = true;
                $reporter->log('warning', 'Replay ativo: registro removido para reaplicar migration fisicamente.', [
                    'migration' => $name,
                    'path' => $path,
                ]);
            }

            $attempt = 0;
            while (true) {
                $attempt++;
                $reporter->log('info', 'Executando migration (determinístico: 1 por vez).', [
                    'migration' => $name,
                    'path' => $path,
                    'attempt' => $attempt,
                ]);
                $reporter->migrationAttempt($name, 'started', $attempt, $path, $replayFromStart, false, false, null, [], 'updater:migrate');

                try {
                    $this->migrator->run([$path], ['step' => false]);
                    $stats['executed']++;
                    $reporter->log('info', 'Migration executada com sucesso.', ['migration' => $name, 'attempt' => $attempt]);
                    $reporter->migrationAttempt($name, 'success', $attempt, $path, $replayFromStart, false, false, null, [], 'updater:migrate');
                    break;
                } catch (Throwable $throwable) {
                    $classification = $this->classifier->classify($throwable);
                    $object = $this->classifier->inferObject($throwable);
                    $details = $this->classifier->extractErrorDetails($throwable);

                    $reporter->log('warning', 'Falha classificada durante migration.', [
                        'migration' => $name,
                        'attempt' => $attempt,
                        'classification' => $classification,
                        'object' => $object,
                        'sqlstate' => $details['sqlstate'],
                        'errno' => $details['errno'],
                        'error' => $throwable->getMessage(),
                    ]);

                    if ($classification === MigrationFailureClassifier::LOCK_RETRYABLE && $attempt <= $lockRetries) {
                        $stats['retried']++;
                        $sleepSeconds = ($retrySleepBase * (2 ** ($attempt - 1))) + ($attempt - 1);
                        $reporter->log('warning', 'Retry por lock/deadlock.', [
                            'migration' => $name,
                            'attempt' => $attempt,
                            'next_wait_seconds' => $sleepSeconds,
                        ]);
                        sleep($sleepSeconds);
                        continue;
                    }

                    $isAutoReconcilableObject = in_array((string) ($object['type'] ?? ''), ['table', 'column', 'index', 'constraint', 'view'], true);
                    $allowAutoReconcile = $shouldReconcileAlreadyExists || $isAutoReconcilableObject;

                    if ($classification === MigrationFailureClassifier::ALREADY_EXISTS && $isEnabled && $allowAutoReconcile && !$strict) {
                        $reconciled = $this->reconciler->reconcile(
                            $repository,
                            $name,
                            $object,
                            $details,
                            is_string($connection) ? $connection : null,
                            false,
                            $runId
                        );

                        if (($reconciled['reconciled'] ?? false) === true) {
                            $stats['reconciled']++;
                            if (($reconciled['warning'] ?? false) === true) {
                                $stats['warnings']++;
                            }
                            $stats['divergences'][] = [
                                'migration' => $name,
                                'type' => 'DIVERGENCE SUSPECTED',
                                'object' => $object,
                                'note' => 'partial_apply_possible',
                            ];
                            $reporter->log('warning', 'Migration reconciliada e marcada como executada.', [
                                'migration' => $name,
                                'result' => $reconciled,
                            ]);
                            $reporter->migrationAttempt($name, 'reconciled', $attempt, $path, $replayFromStart, true, true, null, [
                                'classification' => $classification,
                                'object' => $object,
                                'details' => $details,
                            ], 'updater:migrate');
                            break;
                        }
                    }

                    if ($classification === MigrationFailureClassifier::ALREADY_EXISTS && $strict) {
                        $reporter->log('error', 'Modo estrito ativo: drift exige intervenção manual.', [
                            'migration' => $name,
                            'object' => $object,
                            'sqlstate' => $details['sqlstate'],
                            'errno' => $details['errno'],
                        ]);
                    }

                    if (!$strict && ($this->isRecoverableRuntimeWarning($throwable) || $this->isRecoverableSchemaConstraintMismatch($throwable))) {
                        $stats['warnings']++;
                        $stats['skipped_runtime_warning']++;
                        $stats['divergences'][] = [
                            'migration' => $name,
                            'type' => 'MIGRATION_RUNTIME_WARNING',
                            'object' => $object,
                            'note' => mb_substr($throwable->getMessage(), 0, 500),
                        ];
                        $reporter->log('warning', 'Migration ignorada em modo tolerante por erro recuperável; mantendo pendente para correção manual.', [
                            'migration' => $name,
                            'error' => $throwable->getMessage(),
                        ]);
                        $reporter->migrationAttempt($name, 'warning_skipped', $attempt, $path, $replayFromStart, false, true, $throwable->getMessage(), [
                            'classification' => $classification,
                            'object' => $object,
                            'details' => $details,
                        ], 'updater:migrate');

                        if ($deletedForReplay && !$this->repositoryHasMigration($repository, $name)) {
                            $repository->log($name, $repository->getNextBatchNumber());
                        }
                        break;
                    }

                    $stats['failed']++;
                    $reporter->log('error', 'Falha não recuperável na migration.', [
                        'migration' => $name,
                        'error' => $throwable->getMessage(),
                        'classification' => $classification,
                    ]);
                    if ($deletedForReplay && !$this->repositoryHasMigration($repository, $name)) {
                        $repository->log($name, $repository->getNextBatchNumber());
                    }
                    $reporter->migrationAttempt($name, 'failed', $attempt, $path, $replayFromStart, false, false, $throwable->getMessage(), [
                        'classification' => $classification,
                        'object' => $object,
                        'details' => $details,
                    ], 'updater:migrate');
                    throw $throwable;
                }
            }
        }

        $reporter->log('info', 'Finalizando updater:migrate.', $stats);

        return $stats;
    }


    private function isRecoverableRuntimeWarning(Throwable $throwable): bool
    {
        $message = mb_strtolower($throwable->getMessage());

        return str_contains($message, 'preg_match(): unknown modifier')
            || str_contains($message, 'preg_replace(): unknown modifier')
            || str_contains($message, 'preg_match(): compilation failed');
    }


    private function isRecoverableSchemaConstraintMismatch(Throwable $throwable): bool
    {
        $message = mb_strtolower($throwable->getMessage());

        return str_contains($message, 'cannot be not null: needed in a foreign key constraint')
            && str_contains($message, 'on delete set null');
    }

    private function resolvePaths(array $options): array
    {
        $paths = [];
        if (function_exists('config')) {
            try {
                $paths = array_values(array_filter((array) config('updater.migrate.paths', [])));
            } catch (\Throwable) {
                $paths = [];
            }
        }

        if ($paths === []) {
            try {
                $paths = [database_path('migrations')];
            } catch (\Throwable) {
                $paths = ['database/migrations'];
            }
        }

        $extraPath = trim((string) ($options['path'] ?? ''));
        if ($extraPath !== '') {
            $paths = [$extraPath];
        }

        return $paths;
    }

    private function repositoryHasMigration(object $repository, string $name): bool
    {
        if (!method_exists($repository, 'getRan')) {
            return false;
        }

        return in_array($name, (array) $repository->getRan(), true);
    }
}
