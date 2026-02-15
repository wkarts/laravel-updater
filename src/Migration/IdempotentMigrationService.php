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

        $mode = (string) ($options['mode'] ?? 'tolerant');
        $strict = $mode === 'strict';
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $sleepBaseSeconds = max(1, (int) ($options['retry_sleep_base'] ?? 3));
        $reconcileEnabled = (bool) ($options['reconcile_already_exists'] ?? true);

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
            'pending' => array_keys($pending),
            'connection' => $connection,
            'reconcile_already_exists' => $reconcileEnabled,
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
                $reporter->log('info', 'Executando migration.', ['migration' => $name, 'path' => $path, 'attempt' => $attempt]);

                try {
                    $this->migrator->run([$path], ['step' => true]);
                    $stats['executed']++;
                    $reporter->log('info', 'Migration executada com sucesso.', ['migration' => $name, 'attempt' => $attempt]);
                    break;
                } catch (Throwable $throwable) {
                    $classified = $this->classifier->classifyWithContext($throwable);
                    $classification = (string) $classified['classification'];
                    $object = $this->classifier->inferObject($throwable);
                    $reporter->log('warning', 'Falha classificada durante migration.', [
                        'migration' => $name,
                        'attempt' => $attempt,
                        'classification' => $classification,
                        'object' => $object,
                        'sqlstate' => $classified['sqlstate'],
                        'errno' => $classified['errno'],
                        'error' => $throwable->getMessage(),
                    ]);

                    if ($classification === MigrationFailureClassifier::LOCK_RETRYABLE && $attempt <= $maxRetries) {
                        $stats['retried']++;
                        $sleepSeconds = $this->calculateBackoffSeconds($sleepBaseSeconds, $attempt);
                        $reporter->log('warning', 'Retry por lock/deadlock.', ['migration' => $name, 'attempt' => $attempt, 'sleep_seconds' => $sleepSeconds]);
                        sleep($sleepSeconds);
                        continue;
                    }

                    if (
                        $classification === MigrationFailureClassifier::ALREADY_EXISTS
                        && $strict === false
                        && $reconcileEnabled
                    ) {
                        $reconciled = $this->reconciler->reconcile($repository, $name, $object, is_string($connection) ? $connection : null, $strict);
                        if (($reconciled['reconciled'] ?? false) === true) {
                            $stats['reconciled']++;
                            if (($reconciled['validation']['warning'] ?? false) === true) {
                                $stats['warnings']++;
                            }
                            $stats['divergences'][] = [
                                'migration' => $name,
                                'type' => 'DIVERGENCE_SUSPECTED',
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

                    $stats['failed']++;
                    $reporter->log('error', 'Falha não recuperável na migration.', ['migration' => $name, 'error' => $throwable->getMessage()]);
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

    private function calculateBackoffSeconds(int $base, int $attempt): int
    {
        return max(1, ($base * (2 ** $attempt)) - 1);
    }
}
