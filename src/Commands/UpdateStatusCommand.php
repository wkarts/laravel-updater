<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Console\Command;

class UpdateStatusCommand extends Command
{
    protected $signature = 'system:update:status';
    protected $description = 'Exibe o status atual do updater.';

    public function handle(UpdaterKernel $kernel, LockInterface $lock): int
    {
        $status = $kernel->status();
        $this->table(['Chave', 'Valor'], [
            ['enabled', (string) (int) $status['enabled']],
            ['mode', (string) $status['mode']],
            ['channel', (string) $status['channel']],
            ['revision', (string) $status['revision']],
            ['lock_active', $lock->isAcquired() ? 'true' : 'false'],
            ['last_run', json_encode($status['last_run'], JSON_UNESCAPED_UNICODE)],
        ]);

        return self::SUCCESS;
    }
}
