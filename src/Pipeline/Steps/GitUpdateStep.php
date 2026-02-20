<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;

class GitUpdateStep implements PipelineStepInterface
{
    public function __construct(
        private readonly CodeDriverInterface $codeDriver,
        private readonly ?ManagerStore $managerStore = null,
        private readonly ?ShellRunner $shellRunner = null
    ) {
    }

    public function name(): string { return 'git_update'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $activeSource = $this->managerStore?->activeSource();
        $isDryRun = (bool) ($context['options']['dry_run'] ?? false);
        $requestedUpdateType = trim((string) ($context['options']['update_type'] ?? ''));
        if ($requestedUpdateType === '') {
            $requestedUpdateType = (string) config('updater.git.update_type', 'git_ff_only');
        }
        $requestedTag = trim((string) ($context['options']['target_tag'] ?? config('updater.git.tag', '')));
        $context['git_update_log'][] = sprintf('tipo solicitado: %s', $requestedUpdateType);
        if ($requestedTag !== '') {
            $context['git_update_log'][] = sprintf('tag solicitada: %s', $requestedTag);
        }

        if (is_array($activeSource) && $this->shellRunner !== null) {
            $sourceType = (string) ($activeSource['type'] ?? 'git_ff_only');
            $url = (string) ($activeSource['repo_url'] ?? '');
            $branch = (string) ($activeSource['branch'] ?? config('updater.git.branch', 'main'));
            if ($url !== '' && !$isDryRun) {
                $authMode = (string) ($activeSource['auth_mode'] ?? 'none');
                $username = trim((string) ($activeSource['auth_username'] ?? ''));
                $password = trim((string) ($activeSource['auth_password'] ?? $activeSource['token_encrypted'] ?? ''));

                if ($authMode === 'token' && $password !== '' && str_starts_with($url, 'https://')) {
                    if ($username !== '') {
                        $url = preg_replace('#^https://#', 'https://' . rawurlencode($username) . ':' . rawurlencode($password) . '@', $url) ?: $url;
                    } else {
                        $url = preg_replace('#^https://#', 'https://' . rawurlencode($password) . '@', $url) ?: $url;
                    }
                }

                config([
                    'updater.git.remote_url' => $url,
                    'updater.git.branch' => $branch,
                ]);

                $env = ['GIT_TERMINAL_PROMPT' => '0'];
                $cwd = (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());

                if (!$this->isGitRepository($cwd, $env)) {
                    $autoInit = (bool) config('updater.git.auto_init', false);
                    $forceBootstrap = (bool) ($context['options']['force'] ?? false);

                    if (!$autoInit && !$forceBootstrap) {
                        throw new \RuntimeException('Repositório git ausente em ' . $cwd . '. Habilite UPDATER_GIT_AUTO_INIT=true ou execute com --force para bootstrap automático.');
                    }

                    $this->bootstrapRepository($cwd, $url, $branch, $requestedUpdateType, $requestedTag, $env);
                    $context['git_bootstrapped'] = true;
                } else {
                    $this->shellRunner->runOrFail(['git', 'remote', 'set-url', 'origin', $url], $cwd, $env);
                    $this->shellRunner->runOrFail(['git', 'fetch', 'origin', $branch], $cwd, $env);
                }
            }

            $context['source_id'] = (int) $activeSource['id'];
            $context['source_name'] = (string) $activeSource['name'];
            $context['source_type'] = $sourceType;
        }


// Preparação do working tree: evita falha de merge por arquivos locais (modificados ou untracked).
// É comum existirem artefatos gerados (vendor:publish, assets, views) e ajustes locais de config.
// Para garantir update estável, cria stash automático (incluindo untracked) antes do pull/checkout.
if (!$isDryRun && $this->shellRunner !== null) {
    $cwd = $cwd ?? (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());
    $env = $env ?? ['GIT_TERMINAL_PROMPT' => '0'];
    $this->autoStashWorkingTree($context, $cwd, $env);
}

        $context['revision_before'] = $this->codeDriver->currentRevision();
        $context['git_tag_before'] = $this->resolveCurrentTag();

        if (!$isDryRun) {
            $this->backupDotEnv($context);
        }

        if ($isDryRun) {
            $context['dry_run_plan']['git'] = [
                'atual' => $context['revision_before'],
                'alvo' => $this->codeDriver->statusUpdates()['remote'] ?? null,
                'comandos' => ['git fetch origin ' . config('updater.git.branch', 'main'), 'git rev-list --count HEAD..origin/' . config('updater.git.branch', 'main')],
            ];
            $context['revision_after'] = $context['revision_before'];
            return;
        }

        try {
            $context['revision_after'] = $this->codeDriver->update();
        } catch (\Throwable $e) {
            // Caso clássico: arquivos untracked/ignored (ex.: vendor:publish) bloqueiam merge/checkout.
            // Mesmo com stash -a, alguns ambientes podem recriar assets imediatamente ou manter arquivos fora do controle do git.
            // Estratégia: detectar a assinatura do erro, mover os arquivos conflitantes para uma pasta de quarentena e tentar 1 vez.
            if ($this->shellRunner !== null && $this->shouldRetryAfterUntrackedOverwrite($e)) {
                $cwd = (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());
                $env = ['GIT_TERMINAL_PROMPT' => '0'];
                $this->quarantineUntrackedOverwriteFiles($context, $e->getMessage(), $cwd);
                $context['git_update_log'][] = 'Arquivos untracked conflitantes foram movidos para quarentena e o update será tentado novamente.';
                $context['revision_after'] = $this->codeDriver->update();
            } else {
                throw $e;
            }
        }
        $context['git_tag_after'] = $this->resolveCurrentTag();

        if ($requestedUpdateType === 'git_tag' && $requestedTag !== '') {
            $afterTag = (string) ($context['git_tag_after'] ?? '');
            if ($afterTag === '' || $afterTag !== $requestedTag) {
                throw new \RuntimeException('A tag alvo não foi aplicada corretamente. Esperado: ' . $requestedTag . '; atual: ' . ($afterTag !== '' ? $afterTag : 'sem tag exata'));
            }
        }

        if (($context['revision_before'] ?? null) === ($context['revision_after'] ?? null)) {
            $context['git_update_warning'] = 'Revisão inalterada após update. Isso pode ocorrer se já estava na versão/tag alvo.';

            $allowNoChange = (bool) ($context['options']['allow_no_change_success'] ?? false);
            $hadPreviousRevision = !empty($context['revision_before']) && (string) $context['revision_before'] !== 'N/A';
            $alreadyAtRequestedTag = $requestedUpdateType === 'git_tag'
                && $requestedTag !== ''
                && (string) ($context['git_tag_before'] ?? '') === $requestedTag
                && (string) ($context['git_tag_after'] ?? '') === $requestedTag;
            $nowAtRequestedTag = $requestedUpdateType === 'git_tag'
                && $requestedTag !== ''
                && (string) ($context['git_tag_after'] ?? '') === $requestedTag;

            if (!$allowNoChange && $hadPreviousRevision && !$alreadyAtRequestedTag && !$nowAtRequestedTag) {
                throw new \RuntimeException('Nenhuma atualização real foi aplicada (revision_before == revision_after). Cancelando execução para evitar falso sucesso.');
            }
        }

        $context['git_update_log'][] = sprintf('revision_before: %s', (string) ($context['revision_before'] ?? 'N/A'));
        $context['git_update_log'][] = sprintf('revision_after: %s', (string) ($context['revision_after'] ?? 'N/A'));
        $this->restoreDotEnv($context);
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['revision_before']) && !(bool) ($context['options']['dry_run'] ?? false)) {
            $this->codeDriver->rollback($context['revision_before']);
        }

        $this->restoreDotEnv($context);
    }


private function autoStashWorkingTree(array &$context, string $cwd, array $env = []): void
{
    $allowDirty = (bool) ($context['options']['allow_dirty'] ?? false);
    $autoStash = (bool) ($context['options']['auto_stash'] ?? config('updater.git.auto_stash', true));

    $status = $this->shellRunner?->run(['git', 'status', '--porcelain'], $cwd, $env);
    $porcelain = trim((string) ($status['stdout'] ?? ''));

    // Também considera arquivos ignorados/untracked que podem bloquear merge/checkout
    // (ex.: assets publicados em public/vendor/*).
    $untracked = $this->shellRunner?->run(['git', 'ls-files', '--others', '--exclude-standard'], $cwd, $env);
    $ignored   = $this->shellRunner?->run(['git', 'ls-files', '--others', '-i', '--exclude-standard'], $cwd, $env);

    $hasUntracked = trim((string) ($untracked['stdout'] ?? '')) !== '';
    $hasIgnored   = trim((string) ($ignored['stdout'] ?? '')) !== '';

    if ($porcelain === '' && !$hasUntracked && !$hasIgnored) {
        return;
    }

    if (!$allowDirty && !$autoStash) {
        throw new \RuntimeException("Working tree sujo. Ajuste/commite ou habilite --allow-dirty / UPDATER_GIT_AUTO_STASH=true.\n" . $porcelain);
    }

    $runId = (string) ($context['run_id'] ?? 'manual');
    $msg = 'laravel-updater run ' . $runId . ' ' . date('Y-m-d H:i:s');

    $res = $this->shellRunner->run(['git', 'stash', 'push', '-a', '-m', $msg], $cwd, $env);
    if (($res['exit_code'] ?? 1) !== 0) {
        throw new \RuntimeException('Falha ao criar stash automático antes do update: ' . (($res['stderr'] ?? '') ?: 'erro desconhecido'));
    }

    $context['git_auto_stash'] = true;
    $context['git_auto_stash_message'] = $msg;
    $context['git_update_log'][] = 'stash automático criado para permitir merge/checkout em working tree sujo.';
}

    private function backupDotEnv(array &$context): void
    {
        $base = function_exists('base_path') ? base_path() : (getcwd() ?: '.');
        $envPath = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envPath)) {
            return;
        }

        $storage = function_exists('storage_path')
            ? storage_path('app/updater/env')
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-updater-env';

        if (!is_dir($storage)) {
            @mkdir($storage, 0775, true);
        }

        $backupPath = rtrim((string) $storage, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'env_backup_' . ($context['run_id'] ?? 'manual') . '_' . date('Ymd_His') . '.env';

        if (@copy($envPath, $backupPath)) {
            $context['env_backup_file'] = $backupPath;
        }
    }

    private function restoreDotEnv(array &$context): void
    {
        $backupPath = (string) ($context['env_backup_file'] ?? '');
        if ($backupPath === '' || !is_file($backupPath)) {
            return;
        }

        $base = function_exists('base_path') ? base_path() : (getcwd() ?: '.');
        $envPath = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (@copy($backupPath, $envPath)) {
            $context['env_restored'] = true;
        }
    }

    private function resolveCurrentTag(): ?string
    {
        $cwd = (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        if (!$this->isGitRepository($cwd, $env)) {
            return null;
        }

        $result = $this->shellRunner?->run(['git', 'describe', '--tags', '--exact-match'], $cwd, $env);
        if (!is_array($result) || (int) ($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        $tag = trim((string) ($result['stdout'] ?? ''));

        return $tag !== '' ? $tag : null;
    }

    /** @param array<string,string> $env */
    private function isGitRepository(string $cwd, array $env): bool
    {
        $result = $this->shellRunner?->run(['git', 'rev-parse', '--is-inside-work-tree'], $cwd, $env);

        if (!is_array($result)
            || (int) ($result['exit_code'] ?? 1) !== 0
            || trim((string) ($result['stdout'] ?? '')) !== 'true') {
            return false;
        }

        // Repositório vazio (git init sem commits) não tem HEAD.
        $head = $this->shellRunner?->run(['git', 'rev-parse', '--verify', 'HEAD'], $cwd, $env);
        return is_array($head) && (int) ($head['exit_code'] ?? 1) === 0;
    }

    /** @param array<string,string> $env */
    private function bootstrapRepository(string $cwd, string $url, string $branch, string $requestedUpdateType, string $requestedTag, array $env): void
    {
        // Segurança: NÃO inicializa um repositório git dentro de uma aplicação já deployada
        // (deploy por artefato sem .git). Isso cria um .git vazio, quebra rev-parse HEAD
        // e mascara o problema real (instância não é git-aware).
        // Só permitimos bootstrap quando o diretório está efetivamente vazio.
        $hasApp = is_file($cwd . DIRECTORY_SEPARATOR . 'artisan')
            || is_file($cwd . DIRECTORY_SEPARATOR . 'composer.json')
            || is_dir($cwd . DIRECTORY_SEPARATOR . 'vendor')
            || is_dir($cwd . DIRECTORY_SEPARATOR . 'app');

        // Bootstrap controlado:
        // - Para update_type=git_tag (checkout por tag), permitimos inicializar .git dentro da aplicação
        //   DESDE QUE exista uma source (remote_url) e uma tag alvo. Isso habilita operações "full replace" via git,
        //   sem depender de histórico local anterior.
        // - Para modos que dependem de merge/pull (git_ff_only/git_merge), exigir .git pré-existente com HEAD válido.
        if ($hasApp && !$this->isGitRepository($cwd, $env)) {
            if ($requestedUpdateType !== 'git_tag') {
                throw new \Argws\LaravelUpdater\Exceptions\UpdaterException(
                    'Diretório não é um repositório git válido (sem .git ou repositório vazio/sem HEAD). '
                    . 'Para operar com segurança em modos de merge/pull, esta instância precisa ter sido deployada contendo o diretório .git (clone). '
                    . 'Alternativa: utilize update_type=git_tag (checkout por tag) ou um modo/fonte por artefato (snapshot/zip).'
                );
            }

            if (trim($url) === '' || trim($requestedTag) === '') {
                throw new \Argws\LaravelUpdater\Exceptions\UpdaterException(
                    'Bootstrap git solicitado para update_type=git_tag, mas faltam parâmetros obrigatórios: repo_url e/ou target_tag.'
                );
            }
        }

        $this->shellRunner?->runOrFail(['git', 'init'], $cwd, $env);
        $this->shellRunner?->run(['git', 'remote', 'remove', 'origin'], $cwd, $env);
        $this->shellRunner?->runOrFail(['git', 'remote', 'add', 'origin', $url], $cwd, $env);

        $this->shellRunner?->runOrFail(['git', 'fetch', '--tags', 'origin'], $cwd, $env);

        if ($requestedUpdateType === 'git_tag') {
            // Checkout direto por tag (modo "full replace" via git):
            // - garante HEAD válido
            // - não depende do histórico anterior do servidor (deploy por artefato)
            // - mantém remote configurado para futuras consultas de tags
            $tag = $requestedTag;

            // Normaliza: aceita "vX.Y" ou "X.Y" conforme o remoto.
            // Primeiro tenta buscar a ref da tag explicitamente.
            $fetchTag = $this->shellRunner?->run(['git', 'fetch', '--depth=1', 'origin', 'tag', $tag], $cwd, $env);
            if (!is_array($fetchTag) || (int) ($fetchTag['exit_code'] ?? 1) !== 0) {
                // fallback: busca completa (tags já foram buscadas acima)
                $this->shellRunner?->runOrFail(['git', 'fetch', 'origin', '--tags'], $cwd, $env);
            }

            // Checkout em detached HEAD (mais previsível para tags)
            $this->shellRunner?->runOrFail(['git', 'checkout', '--detach', $tag], $cwd, $env);
            $this->shellRunner?->runOrFail(['git', 'reset', '--hard'], $cwd, $env);
            return;
        }

        // Modos baseados em branch (ff_only/merge): exige histórico e upstream
        $fetch = $this->shellRunner?->run(['git', 'fetch', '--depth=1', 'origin', $branch], $cwd, $env);
        if (!is_array($fetch) || (int) ($fetch['exit_code'] ?? 1) !== 0) {
            $this->shellRunner?->runOrFail(['git', 'fetch', 'origin', $branch], $cwd, $env);
        }

        $this->shellRunner?->runOrFail(['git', 'checkout', '-B', $branch], $cwd, $env);
        $this->shellRunner?->runOrFail(['git', 'reset', '--hard', 'FETCH_HEAD'], $cwd, $env);
        $this->shellRunner?->run(['git', 'branch', '--set-upstream-to=origin/' . $branch, $branch], $cwd, $env);
    }

    private function shouldRetryAfterUntrackedOverwrite(\Throwable $e): bool
    {
        $msg = mb_strtolower($e->getMessage());
        return str_contains($msg, 'untracked working tree files would be overwritten')
            || str_contains($msg, 'would be overwritten by merge')
            || str_contains($msg, 'please move or remove them before you merge');
    }

    private function quarantineUntrackedOverwriteFiles(array &$context, string $message, string $cwd): void
    {
        // Extrai lista de arquivos do erro do git (linhas com tab prefix).
        $files = [];
        foreach (preg_split('/\r?\n/', $message) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '\t') {
                continue;
            }
            $p = trim(ltrim($line, "\t"));
            if ($p !== '') {
                $files[] = $p;
            }
        }

        // Fallback: quando o git não inclui tab, tenta pegar caminhos comuns do updater.
        if (empty($files)) {
            $candidates = [
                'public/vendor/laravel-updater',
                'resources/views/vendor/laravel-updater',
            ];
            foreach ($candidates as $c) {
                if (is_dir($cwd . DIRECTORY_SEPARATOR . $c) || is_file($cwd . DIRECTORY_SEPARATOR . $c)) {
                    $files[] = $c;
                }
            }
        }

        if (empty($files)) {
            return;
        }

        $runId = (string) ($context['run_id'] ?? 'manual');
        $stamp = date('Ymd_His');
        $quarantineBase = (function_exists('storage_path') ? storage_path('app/updater/quarantine') : ($cwd . DIRECTORY_SEPARATOR . 'storage/app/updater/quarantine'));
        if (!is_dir($quarantineBase)) {
            @mkdir($quarantineBase, 0775, true);
        }
        $destDir = rtrim($quarantineBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'run_' . $runId . '_' . $stamp;
        @mkdir($destDir, 0775, true);

        foreach (array_unique($files) as $rel) {
            $src = $cwd . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (!file_exists($src)) {
                continue;
            }

            $dst = $destDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            $dstParent = dirname($dst);
            if (!is_dir($dstParent)) {
                @mkdir($dstParent, 0775, true);
            }

            // Move (rename) quando possível; caso contrário copia e remove.
            if (!@rename($src, $dst)) {
                if (is_file($src)) {
                    @copy($src, $dst);
                    @unlink($src);
                } elseif (is_dir($src)) {
                    // Remoção recursiva simples
                    $this->copyDir($src, $dst);
                    $this->deleteDir($src);
                }
            }

            $context['git_quarantined_files'][] = $rel;
        }

        // Garante que o working tree está realmente limpo para a segunda tentativa.
        if ($this->shellRunner !== null) {
            $this->shellRunner->run(['git', 'clean', '-fd'], $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        }
    }

    private function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0775, true);
        $items = @scandir($src);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . DIRECTORY_SEPARATOR . $item;
            $to = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                @copy($from, $to);
            }
        }
    }

    private function deleteDir(string $dir): void
    {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

}
