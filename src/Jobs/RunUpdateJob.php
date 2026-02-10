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
        $kernel->run($this->options);
    }
}
