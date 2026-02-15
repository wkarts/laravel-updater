<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Illuminate\Console\Command;

class UpdateEnvSyncCommand extends Command
{
    protected $signature = 'system:update:env-sync
        {--file=.env : Arquivo .env alvo}
        {--profile=default : Perfil (default|production|homolog)}
        {--write : Persistir alterações no arquivo .env}';

    protected $description = 'Sincroniza apenas variáveis UPDATER_* ausentes no .env atual, sem sobrescrever chaves existentes.';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $profile = (string) $this->option('profile');

        $stubMap = [
            'default' => __DIR__ . '/../../stubs/env/updater.default.env.example',
            'production' => __DIR__ . '/../../stubs/env/updater.production.env.example',
            'homolog' => __DIR__ . '/../../stubs/env/updater.homolog.env.example',
        ];

        if (!array_key_exists($profile, $stubMap)) {
            $this->error('Perfil inválido. Use: default, production ou homolog.');

            return self::INVALID;
        }

        $stubFile = $stubMap[$profile];
        if (!is_file($stubFile)) {
            $this->error('Stub de perfil não encontrado: ' . $stubFile);

            return self::FAILURE;
        }

        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $template = (string) file_get_contents($stubFile);

        $missing = $this->extractMissingUpdaterLines($current, $template);

        if ($missing === []) {
            $this->info('Nenhuma chave UPDATER_* ausente. Seu .env já está sincronizado.');

            return self::SUCCESS;
        }

        $this->warn('Chaves UPDATER_* ausentes encontradas:');
        foreach ($missing as $line) {
            $this->line($line);
        }

        if (!(bool) $this->option('write')) {
            $this->line('Use --write para adicionar somente essas chaves no arquivo .env atual.');

            return self::SUCCESS;
        }

        $append = PHP_EOL . '# --- updater sync (' . $profile . ') ---' . PHP_EOL . implode(PHP_EOL, $missing) . PHP_EOL;
        file_put_contents($file, rtrim($current) . $append);

        $this->info('Sincronização concluída sem sobrescrever valores existentes: ' . $file);

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function extractMissingUpdaterLines(string $current, string $template): array
    {
        $existing = [];
        foreach (preg_split('/\r\n|\r|\n/', $current) ?: [] as $line) {
            if (preg_match('/^\s*(UPDATER_[A-Z0-9_]+)\s*=/', $line, $matches) === 1) {
                $existing[$matches[1]] = true;
            }
        }

        $missing = [];
        foreach (preg_split('/\r\n|\r|\n/', $template) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(UPDATER_[A-Z0-9_]+)\s*=/', $trimmed, $matches) === 1) {
                if (!isset($existing[$matches[1]])) {
                    $missing[] = $trimmed;
                }
            }
        }

        return $missing;
    }
}
