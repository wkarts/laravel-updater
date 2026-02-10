<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Console\Command;

class UpdateCheckCommand extends Command
{
    protected $signature = 'system:update:check';
    protected $description = 'Verifica se há atualização disponível.';

    public function handle(UpdaterKernel $kernel): int
    {
        $status = $kernel->check();
        $this->table(['Chave', 'Valor'], collect($status)->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v]));

        return self::SUCCESS;
    }
}
