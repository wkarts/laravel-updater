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
        $reconcileAlreadyExists = (bool) ($options['reconcile_already_exists'] ?? true);
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
        $pending = array_filter(
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
            'divergences' => [],
        ];

        $reporter->log('info', 'Iniciando updater:migrate.', [
            'mode' => $mode,
            'dry_run' => $dryRun,
            'idempotent' => $isEnabled,
            'pending' => array_keys($pending),
            'connection' => $connection,
        ]);

        foreach ($pending as $name => $path) {
            if ($dryRun) {
                $simulated = $this->driftDetector->inspect($path, is_string($connection) ? $connection : null);
                $stats['skipped_dry_run']++;
                $reporter->log('info', 'Dry-run de migration.', ['migration' => $name, 'path' => $path, 'simulation' => $simulated]);
                continue;
            }

            $attempt = 0;
            while (true) {
                $attempt++;
                $reporter->log('info', 'Executando migration (determinístico: 1 por vez).', [
                    'migration' => $name,
                    'path' => $path,
                    'attempt' => $attempt,
                ]);

                try {
                    $this->migrator->run([$path], ['step' => false]);
                    $stats['executed']++;
                    $reporter->log('info', 'Migration executada com sucesso.', ['migration' => $name, 'attempt' => $attempt]);
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

                    if ($classification === MigrationFailureClassifier::ALREADY_EXISTS && $isEnabled && $reconcileAlreadyExists && !$strict) {
                        $reconciled = $this->reconciler->reconcile(
                            $repository,
                            $name,
                            $object,
                            $details,
                            is_string($connection) ? $connection : null,
                            false
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

                    $stats['failed']++;
                    $reporter->log('error', 'Falha não recuperável na migration.', [
                        'migration' => $name,
                        'error' => $throwable->getMessage(),
                        'classification' => $classification,
                    ]);
                    throw $throwable;
                }
            }
        }

        $reporter->log('info', 'Finalizando updater:migrate.', $stats);

        return $stats;
    }

    private function resolvePaths(array $options): array
    {
        $paths = array_values(array_filter((array) config('updater.migrate.paths', [])));

        if ($paths === []) {
            $paths = [database_path('migrations')];
        }

        $extraPath = trim((string) ($options['path'] ?? ''));
        if ($extraPath !== '') {
            $paths = [$extraPath];
        }

        return $paths;
    }
}
