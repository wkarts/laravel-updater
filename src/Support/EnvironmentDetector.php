<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Exceptions\UpdaterException;

class EnvironmentDetector
{
    public function ensureCli(bool $allowHttp = false): void
    {
        if ($allowHttp) {
            return;
        }

        if (PHP_SAPI !== 'cli') {
            throw new UpdaterException('O updater só pode ser executado via CLI.');
        }
    }

    public function ensureWritable(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_writable($path)) {
                throw new UpdaterException("Sem permissão de escrita: {$path}");
            }
        }
    }
}
