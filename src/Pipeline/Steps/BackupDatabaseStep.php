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
        if (!$this->enabled || !$this->isPreUpdateBackupEnabled()) {
            return false;
        }

        if ((bool) ($context['options']['no_backup'] ?? false)) {
            return false;
        }

        $type = $this->resolveBackupType($context);

        return in_array($type, ['full', 'full+snapshot', 'full+database', 'database'], true);
    }

    public function handle(array &$context): void
    {
        $context['backup_file'] = $this->backupDriver->backup('db_' . date('Ymd_His'));
        $this->store->registerArtifact('backup', $context['backup_file'], ['run_id' => $context['run_id'] ?? null]);
    }



    private function isPreUpdateBackupEnabled(): bool
    {
        if (!function_exists('config')) {
            return true;
        }

        return (bool) config('updater.backup.pre_update', true);
    }

    private function configuredPreUpdateBackupType(): string
    {
        if (!function_exists('config')) {
            return 'full';
        }

        return (string) config('updater.backup.pre_update_type', 'full');
    }

    private function resolveBackupType(array $context): string
    {
        $raw = trim((string) ($context['options']['backup_type'] ?? $this->configuredPreUpdateBackupType()));
        $raw = strtolower(str_replace(' ', '', $raw));

        return match ($raw) {
            'snapshot', 'database', 'full+snapshot', 'full+database', 'full' => $raw,
            default => 'full',
        };
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['backup_file'])) {
            $this->backupDriver->restore($context['backup_file']);
        }
    }
}
