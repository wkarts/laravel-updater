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
        {--seed : Executa fluxo de seed}
        {--install-seed-default : Também executa DatabaseSeeder (somente instalação inicial)}
        {--seeder=* : Seeder específica (pode repetir)}
        {--seeders= : Lista separada por vírgula de seeders}
        {--sql-path= : Caminho customizado para patch SQL}
        {--no-backup : Não executa backup}
        {--no-snapshot : Não executa snapshot}
        {--no-build : Não executa build de assets}
        {--allow-dirty : Permite git dirty}
        {--dry-run : Executa apenas simulação (sem alterações)}
        {--update-type= : Tipo de update (git_merge|git_ff_only|git_tag|zip_release)}
        {--tag= : Tag alvo para update por tag}
        {--allow-http : Permite execução disparada via HTTP/UI}
        {--strict-migrate : Não reconcilia drift de migrations}
        {--source-id= : ID da fonte a ativar antes da execução}
        {--profile-id= : ID do perfil a ativar antes da execução}
        {--pre-command=* : Comando pré-update (pode repetir)}
        {--post-command=* : Comando pós-update (pode repetir)}';
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

        $profile = null;
        if ($profileId > 0) {
            $profile = $managerStore->findProfile($profileId);
        }
        if ($profile === null) {
            $profile = $managerStore->activeProfile();
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
            'force' => (bool) $this->option('force'),
            'seed' => (bool) $this->option('seed'),
            'install_seed_default' => (bool) $this->option('install-seed-default'),
            'seeders' => array_values(array_filter($seeders)),
            'sql_path' => $this->option('sql-path') ?: null,
            'no_backup' => (bool) $this->option('no-backup'),
            'no_snapshot' => (bool) $this->option('no-snapshot'),
            'no_build' => (bool) $this->option('no-build'),
            'allow_dirty' => (bool) $this->option('allow-dirty'),
            'dry_run' => (bool) $this->option('dry-run'),
            'update_type' => $updateType,
            'target_tag' => $tag,
            'allow_http' => (bool) $this->option('allow-http'),
            'strict_migrate' => (bool) $this->option('strict-migrate'),
            'source_id' => $sourceId > 0 ? $sourceId : null,
            'profile_id' => $profileId > 0 ? $profileId : null,
            'rollback_on_fail' => (bool) ($profile['rollback_on_fail'] ?? true),
            'snapshot_include_vendor' => (bool) ($profile['snapshot_include_vendor'] ?? config('updater.snapshot.include_vendor', false)),
            'pre_update_commands' => $this->resolvePreUpdateCommands($profile, (array) $this->option('pre-command')),
            'post_update_commands' => $this->resolvePostUpdateCommands($profile, (array) $this->option('post-command')),
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

    /** @param array<string,mixed>|null $profile */
    private function resolvePreUpdateCommands(?array $profile, array $manual): array
    {
        $commands = [];

        $profileRaw = (string) ($profile['pre_update_commands'] ?? '');
        foreach (preg_split('/\r\n|\r|\n/', $profileRaw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $commands[] = $line;
        }

        foreach ($manual as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $commands[] = $line;
        }

        if ($commands === []) {
            $envRaw = trim((string) env('UPDATER_PRE_UPDATE_COMMANDS', ''));
            if ($envRaw !== '') {
                foreach (preg_split('/;;|\r\n|\r|\n/', $envRaw) ?: [] as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $commands[] = $line;
                }
            }
        }

        return array_values(array_unique($commands));
    }

    /** @param array<string,mixed>|null $profile */
    private function resolvePostUpdateCommands(?array $profile, array $manual): array
    {
        $commands = [];

        $profileRaw = (string) ($profile['post_update_commands'] ?? '');
        foreach (preg_split('/\r\n|\r|\n/', $profileRaw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $commands[] = $line;
        }

        foreach ($manual as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $commands[] = $line;
        }

        if ($commands === []) {
            $envRaw = trim((string) env('UPDATER_POST_UPDATE_COMMANDS', ''));
            if ($envRaw !== '') {
                foreach (preg_split('/;;|\r\n|\r|\n/', $envRaw) ?: [] as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $commands[] = $line;
                }
            }
        }

        return array_values(array_unique($commands));
    }
}
