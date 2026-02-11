<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Console\Command;
use Throwable;

class UpdateCheckCommand extends Command
{
    protected $signature = 'system:update:check {--allow-dirty : Permite check com árvore git suja}';
    protected $description = 'Verifica se há atualização disponível.';

    public function handle(UpdaterKernel $kernel): int
    {
        try {
            $status = $kernel->check((bool) $this->option('allow-dirty'));
            $this->table(['Chave', 'Valor'], collect($status)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v)]));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());
            return self::FAILURE;
        }
    }
}
