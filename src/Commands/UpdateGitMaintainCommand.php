<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Support\GitMaintenance;
use Illuminate\Console\Command;

class UpdateGitMaintainCommand extends Command
{
    protected $signature = 'updater:git:maintain {--reason=manual : Motivo/identificador (manual, schedule, post_update, pre_update)}';
    protected $description = 'Executa manutenção automática do repositório git (.git) para evitar crescimento indefinido.';

    public function handle(GitMaintenance $maintenance): int
    {
        $reason = (string) $this->option('reason');
        $report = $maintenance->maintain($reason);

        $this->info('Git maintenance: ' . ($report['skipped'] ? 'SKIPPED' : 'DONE'));
        $this->line('Before: ' . ($report['before_mb'] ?? 0) . 'MB');
        $this->line('After : ' . ($report['after_mb'] ?? 0) . 'MB');

        if (!empty($report['actions'])) {
            $this->line('');
            foreach ($report['actions'] as $a) {
                $this->line(sprintf('- %s: %s', $a['action'], ($a['ok'] ? 'OK' : 'FAIL')));
                if (!$a['ok'] && !empty($a['stderr'])) {
                    $this->line('  ' . trim((string) $a['stderr']));
                }
            }
        }

        return self::SUCCESS;
    }
}
