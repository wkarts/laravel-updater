<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Support\GitMaintenance;
use Illuminate\Console\Command;

class UpdateGitSizeCommand extends Command
{
    protected $signature = 'updater:git:size';
    protected $description = 'Mostra o tamanho atual do diretório .git do repositório alvo do updater.';

    public function handle(GitMaintenance $maintenance): int
    {
        $bytes = $maintenance->sizeBytes();
        $mb = (int) ceil($bytes / (1024 * 1024));

        $this->info('Tamanho do .git: ' . $mb . 'MB (' . $bytes . ' bytes)');

        return self::SUCCESS;
    }
}
