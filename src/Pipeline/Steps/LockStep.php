<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\LockInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Exceptions\UpdaterException;

class LockStep implements PipelineStepInterface
{
    public function __construct(private readonly LockInterface $lock, private readonly int $timeout)
    {
    }

    public function name(): string { return 'lock'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        if (!$this->lock->acquire('system-update', $this->timeout)) {
            throw new UpdaterException('Não foi possível adquirir lock de atualização. Possível execução anterior travada. Use a seção Segurança do Updater para verificar/limpar o lock ou aguarde o término de uma execução em andamento.');
        }
        $context['lock'] = true;
    }

    public function rollback(array &$context): void
    {
        $this->lock->release();
    }
}
