<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Console\Command;

class UpdateStatusCommand extends Command
{
    protected $signature = 'system:update:status';
    protected $description = 'Exibe o status atual do updater.';

    public function handle(UpdaterKernel $kernel): int
    {
        $status = $kernel->status();
        $this->table(['Chave', 'Valor'], collect($status)->map(fn ($v, $k) => [$k, (string) $v]));

        return self::SUCCESS;
    }
}
