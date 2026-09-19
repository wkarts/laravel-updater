<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Jobs;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;

class RunUpdateJob
{
    public function __construct(private readonly array $options = [])
    {
    }

    public function handle(UpdaterKernel $kernel): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException(
                'RunUpdateJob recusado fora do CLI. Queue síncrona não pode executar atualização real dentro do PHP-FPM.'
            );
        }

        $kernel->run($this->options);
    }
}
