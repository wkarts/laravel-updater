<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Console\Command;
use Throwable;

class UpdateRollbackCommand extends Command
{
    protected $signature = 'system:update:rollback {--backup= : Caminho do dump} {--snapshot= : Caminho do snapshot} {--revision= : Revision git} {--force : Executa sem confirmação}';
    protected $description = 'Executa rollback completo da última atualização.';

    public function handle(UpdaterKernel $kernel): int
    {
        if (!$this->option('force') && !$this->confirm('Confirma rollback do sistema?')) {
            $this->warn('Rollback cancelado.');
            return self::INVALID;
        }

        $context = array_filter([
            'backup_file' => $this->option('backup'),
            'snapshot_file' => $this->option('snapshot'),
            'revision_before' => $this->option('revision'),
        ]);

        try {
            $kernel->rollback($context);
            $this->info('Rollback executado com sucesso.');
            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());
            return self::FAILURE;
        }
    }
}
