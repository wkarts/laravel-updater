<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Illuminate\Console\Command;
use Throwable;

class UpdateRunCommand extends Command
{
    protected $signature = 'system:update:run
        {--force : Executa sem confirmação}
        {--seed : Executa seed padrão}
        {--seeder=* : Seeder específica (pode repetir)}
        {--seeders= : Lista separada por vírgula de seeders}
        {--sql-path= : Caminho customizado para patch SQL}
        {--no-backup : Não executa backup}
        {--no-snapshot : Não executa snapshot}
        {--no-build : Não executa build de assets}
        {--allow-dirty : Permite git dirty}
        {--dry-run : Executa apenas simulação (sem alterações)}';
    protected $description = 'Executa a atualização completa do sistema.';

    public function handle(UpdaterKernel $kernel, ManagerStore $managerStore): int
    {
        if (!$this->option('force') && !$this->confirm('Confirma execução da atualização em produção?')) {
            $this->warn('Operação cancelada.');

            return self::INVALID;
        }

        $sourceId = (int) ($this->option('source-id') ?: 0);
        if ($sourceId > 0) {
            $managerStore->setActiveSource($sourceId);
        }

        $profileId = (int) ($this->option('profile-id') ?: 0);
        if ($profileId > 0) {
            $managerStore->activateProfile($profileId);
        }

        $updateType = (string) ($this->option('update-type') ?: '');
        if ($updateType !== '') {
            config(['updater.git.update_type' => $updateType]);
        }

        $tag = trim((string) ($this->option('tag') ?: ''));
        if ($tag !== '') {
            config(['updater.git.tag' => $tag]);
        }

        $seeders = (array) $this->option('seeder');
        if (is_string($this->option('seeders')) && trim((string) $this->option('seeders')) !== '') {
            $seeders = array_merge($seeders, array_map('trim', explode(',', (string) $this->option('seeders'))));
        }

        $options = [
            'seed' => (bool) $this->option('seed'),
            'seeders' => array_values(array_filter($seeders)),
            'sql_path' => $this->option('sql-path') ?: null,
            'no_backup' => (bool) $this->option('no-backup'),
            'no_snapshot' => (bool) $this->option('no-snapshot'),
            'no_build' => (bool) $this->option('no-build'),
            'allow_dirty' => (bool) $this->option('allow-dirty'),
            'dry_run' => (bool) $this->option('dry-run'),
        ];

        try {
            $context = $kernel->run($options);
            $this->info('Atualização concluída com sucesso.');
            $this->line(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
