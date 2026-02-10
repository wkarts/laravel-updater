<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\StateStore;

class BackupDatabaseStep implements PipelineStepInterface
{
    public function __construct(private readonly BackupDriverInterface $backupDriver, private readonly StateStore $store, private readonly bool $enabled)
    {
    }

    public function name(): string { return 'backup_database'; }

    public function shouldRun(array $context): bool
    {
        return $this->enabled && !(bool) ($context['options']['no_backup'] ?? false);
    }

    public function handle(array &$context): void
    {
        $context['backup_file'] = $this->backupDriver->backup('db_' . date('Ymd_His'));
        $this->store->registerArtifact('backup', $context['backup_file'], ['run_id' => $context['run_id'] ?? null]);
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['backup_file'])) {
            $this->backupDriver->restore($context['backup_file']);
        }
    }
}
