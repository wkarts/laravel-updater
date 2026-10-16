<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Jobs;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunRollbackJob implements ShouldQueue
{
    use Queueable;

    public function handle(UpdaterKernel $kernel): void
    {
        $kernel->rollback();
    }
}
