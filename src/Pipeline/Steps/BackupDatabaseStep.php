<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;

class BackupDatabaseStep implements PipelineStepInterface
{
    public function __construct(private readonly BackupDriverInterface $backupDriver, private readonly bool $enabled)
    {
    }

    public function name(): string { return 'backup_database'; }
    public function shouldRun(array $context): bool { return $this->enabled; }

    public function handle(array &$context): void
    {
        $context['backup_file'] = $this->backupDriver->backup('db_' . date('Ymd_His'));
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['backup_file'])) {
            $this->backupDriver->restore($context['backup_file']);
        }
    }
}
