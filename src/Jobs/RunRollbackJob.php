<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Jobs;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;

class RunRollbackJob
{
    public function handle(UpdaterKernel $kernel): void
    {
        $kernel->rollback();
    }
}
