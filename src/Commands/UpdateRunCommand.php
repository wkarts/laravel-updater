<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Console\Command;
use Throwable;

class UpdateRunCommand extends Command
{
    protected $signature = 'system:update:run {--force : Executa sem confirmação}';
    protected $description = 'Executa a atualização completa do sistema.';

    public function handle(UpdaterKernel $kernel): int
    {
        if (!$this->option('force') && !$this->confirm('Confirma execução da atualização em produção?')) {
            $this->warn('Operação cancelada.');
            return self::INVALID;
        }

        try {
            $context = $kernel->run();
            $this->info('Atualização concluída com sucesso.');
            $this->line(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());
            return self::FAILURE;
        }
    }
}
