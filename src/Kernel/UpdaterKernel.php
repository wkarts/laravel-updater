<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Kernel;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Exceptions\RollbackException;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Pipeline\Steps\BackupDatabaseStep;
use Argws\LaravelUpdater\Pipeline\Steps\BuildAssetsStep;
use Argws\LaravelUpdater\Pipeline\Steps\CacheClearStep;
use Argws\LaravelUpdater\Pipeline\Steps\ComposerInstallStep;
use Argws\LaravelUpdater\Pipeline\Steps\GitUpdateStep;
use Argws\LaravelUpdater\Pipeline\Steps\HealthCheckStep;
use Argws\LaravelUpdater\Pipeline\Steps\LockStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOffStep;
use Argws\LaravelUpdater\Pipeline\Steps\MaintenanceOnStep;
use Argws\LaravelUpdater\Pipeline\Steps\MigrateStep;
use Argws\LaravelUpdater\Pipeline\Steps\SeedStep;
use Argws\LaravelUpdater\Pipeline\Steps\SnapshotCodeStep;
use Argws\LaravelUpdater\Pipeline\Steps\SqlPatchStep;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Psr\Log\LoggerInterface;
use Throwable;

class UpdaterKernel
{
    public function __construct(
        private readonly EnvironmentDetector $environmentDetector,
        private readonly UpdatePipeline $pipeline,
        private readonly CodeDriverInterface $codeDriver,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function makePipeline(array $services): UpdatePipeline
    {
        return new UpdatePipeline([
            new LockStep($services['lock'], (int) config('updater.lock.timeout', 600)),
            new MaintenanceOnStep($services['shell']),
            new BackupDatabaseStep($services['backup'], (bool) config('updater.backup.enabled', true)),
            new SnapshotCodeStep($services['shell'], $services['files'], config('updater.snapshot')),
            new GitUpdateStep($services['code']),
            new ComposerInstallStep($services['shell']),
            new MigrateStep($services['shell']),
            new SeedStep($services['shell']),
            new SqlPatchStep((string) config('updater.sql_patch_path')),
            new BuildAssetsStep($services['shell'], (bool) config('updater.build_assets', false)),
            new CacheClearStep($services['shell']),
            new HealthCheckStep(config('updater.healthcheck')),
            new MaintenanceOffStep($services['shell'], $services['lock']),
        ], $services['logger']);
    }

    public function check(): array
    {
        $this->environmentDetector->ensureCli();

        return [
            'enabled' => (bool) config('updater.enabled', true),
            'current_revision' => $this->codeDriver->currentRevision(),
            'has_updates' => $this->codeDriver->hasUpdates(),
        ];
    }

    public function run(): array
    {
        $this->environmentDetector->ensureCli();
        $context = [
            'started_at' => now()->toIso8601String(),
            'idempotency_key' => hash('sha256', (string) now()->timestamp . php_uname()),
        ];

        try {
            $this->pipeline->run($context);
            $context['status'] = 'success';
            $this->logger->info('updater.run.success', $context);
            return $context;
        } catch (Throwable $throwable) {
            $context['status'] = 'failed';
            $context['error'] = $throwable->getMessage();
            $this->logger->error('updater.run.failed', $context);
            throw $throwable;
        }
    }

    public function rollback(array $context): void
    {
        $this->environmentDetector->ensureCli();

        try {
            $this->pipeline->rollback($context);
            $this->logger->warning('updater.rollback.success', $context);
        } catch (Throwable $throwable) {
            $this->logger->error('updater.rollback.failed', ['error' => $throwable->getMessage()]);
            throw new RollbackException('Rollback falhou: ' . $throwable->getMessage(), previous: $throwable);
        }
    }

    public function status(): array
    {
        return [
            'enabled' => (bool) config('updater.enabled', true),
            'mode' => (string) config('updater.mode', 'inplace'),
            'channel' => (string) config('updater.channel', 'stable'),
            'revision' => $this->codeDriver->currentRevision(),
        ];
    }
}
