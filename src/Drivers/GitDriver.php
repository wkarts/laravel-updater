<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Drivers;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Exceptions\GitException;
use Argws\LaravelUpdater\Support\ShellRunner;

class GitDriver implements CodeDriverInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly array $config)
    {
    }

    private function gitEnv(array $extra = []): array
    {
        // Evita que o git fique aguardando input interativo (credenciais/merge editor),
        // o que travaria a pipeline e manteria a execução em "running" indefinidamente.
        // Em caso de necessidade de credenciais, o comando deve falhar rápido.
        $base = [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => 'echo',
            'SSH_ASKPASS' => 'echo',
            // Impede abertura de editor (merge message / rebase todo), que causaria “run eterno”.
            'GIT_EDITOR' => 'true',
            'GIT_SEQUENCE_EDITOR' => 'true',
            'GIT_MERGE_AUTOEDIT' => 'no',
        ];

        return $extra ? array_merge($base, $extra) : $base;
    }


    public function currentRevision(): string
    {
        if (!$this->isGitRepository()) {
            return 'N/A';
        }

        return trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'], $this->cwd(), $this->gitEnv())['stdout']);
    }

    public function hasUpdates(): bool
    {
        return $this->statusUpdates()['has_updates'];
    }

    public function statusUpdates(): array
    {
        if (!$this->isGitRepository()) {
            $cfg = $this->runtimeConfig();
            $assumeBehind = (bool) ($cfg['first_run_assume_behind'] ?? true);
            $assumedCommits = max(1, (int) ($cfg['first_run_assume_behind_commits'] ?? 1));

            return [
                'local' => 'N/A',
                'remote' => 'N/A',
                'behind_by_commits' => $assumeBehind ? $assumedCommits : 0,
                'ahead_by_commits' => 0,
                'has_updates' => $assumeBehind,
                'latest_tag' => null,
                'has_update_by_tag' => false,
                'first_run_assumed_update' => $assumeBehind,
            ];
        }

        $config = $this->runtimeConfig();
        $remote = (string) ($config['remote'] ?? 'origin');
        $branch = (string) ($config['branch'] ?? 'main');
        $this->shellRunner->runOrFail(['git', 'fetch', $remote, $branch], $this->cwd(), $this->gitEnv());

        $local = trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'], $this->cwd(), $this->gitEnv())['stdout']);
        $remoteHead = trim($this->shellRunner->runOrFail(['git', 'rev-parse', "{$remote}/{$branch}"], $this->cwd(), $this->gitEnv())['stdout']);

        $ahead = (int) trim($this->shellRunner->runOrFail(['git', 'rev-list', '--count', "{$remote}/{$branch}..HEAD"], $this->cwd(), $this->gitEnv())['stdout']);
        $behind = (int) trim($this->shellRunner->runOrFail(['git', 'rev-list', '--count', "HEAD..{$remote}/{$branch}"], $this->cwd(), $this->gitEnv())['stdout']);

        $latestTag = $this->resolveRemoteTagLatest();
        $currentTag = $this->currentTag();

        return [
            'local' => $local,
            'remote' => $remoteHead,
            'behind_by_commits' => $behind,
            'ahead_by_commits' => $ahead,
            'has_updates' => $behind > 0,
            'latest_tag' => $latestTag,
            'has_update_by_tag' => $latestTag !== null && $latestTag !== '' && $latestTag !== $currentTag,
        ];
    }

    /** @return array<int,string> */
    public function listTags(int $limit = 30): array
    {
        if (!$this->isGitRepository()) {
            return [];
        }

        $config = $this->runtimeConfig();
        $remote = (string) ($config['remote'] ?? 'origin');
        $this->shellRunner->runOrFail(['git', 'fetch', '--tags', $remote], $this->cwd(), $this->gitEnv());

        $output = $this->shellRunner->runOrFail(['git', 'tag', '--list', '--sort=-version:refname'], $this->cwd(), $this->gitEnv())['stdout'];
        $tags = array_values(array_filter(array_map('trim', explode("\n", (string) $output))));

        return array_slice($tags, 0, max(1, $limit));
    }

    public function resolveRemoteTagLatest(): ?string
    {
        $tags = $this->listTags(1);

        return $tags[0] ?? null;
    }

    public function isWorkingTreeClean(): bool
    {
        if (!$this->isGitRepository()) {
            return true;
        }

        $config = $this->runtimeConfig();
        $allowlist = (array) ($config['dirty_allowlist'] ?? []);
        // Defaults úteis (sem bloquear update por alterações típicas de ambiente)
        if ($allowlist === []) {
            $allowlist = ['config/updater.php', '.env', 'storage/', 'bootstrap/cache/'];
        }

        $result = $this->shellRunner->runOrFail(['git', 'status', '--porcelain'], $this->cwd(), $this->gitEnv());
        $lines = array_filter(array_map('trim', explode("\n", (string) $result['stdout'])));

        foreach ($lines as $line) {
            // Formato: "XY path" ou "XY path -> path"
            $path = trim((string) preg_replace('/^[A-Z\?\!\s]{1,3}/', '', $line));
            $path = trim((string) preg_replace('/^\s*\"?(.*?)\"?$/', '$1', $path));
            // Se houver rename "a -> b", pega o destino
            if (str_contains($path, '->')) {
                $parts = array_map('trim', explode('->', $path));
                $path = end($parts) ?: $path;
            }

            $ignored = false;
            foreach ($allowlist as $prefix) {
                $prefix = (string) $prefix;
                if ($prefix === '') {
                    continue;
                }
                if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/') || str_starts_with($path, $prefix)) {
                    $ignored = true;
                    break;
                }
            }

            if (!$ignored) {
                return false;
            }
        }

        return true;
    }

    public function update(): string
    {
        $path = $this->path;
        $remote = $this->remote;
        $branch = $this->branch;
        $mode = strtolower((string) config('updater.git.default_update_mode', env('UPDATER_GIT_DEFAULT_UPDATE_MODE', 'merge')));
        $tag = (string) config('updater.git.target_tag', env('UPDATER_GIT_TARGET_TAG', ''));

        $timeout = (int) env('UPDATER_STEP_TIMEOUT_GIT', 600);
        $depth = (int) env('UPDATER_GIT_SHALLOW_DEPTH', 50);

        // Mantém repo saudável e evita crescimento do .git
        if ((bool) env('UPDATER_GIT_AUTO_PRUNE', true)) {
            $this->shellRunner->runOrFailWithTimeout(['git', 'fetch', '--prune', '--prune-tags', $remote], $path, [], $timeout);
        }

        // Nunca baixar todos os branches: fetch somente do branch/tag alvo
        if ($mode === 'tag') {
            if ($tag === '') {
                throw new \RuntimeException('Modo tag ativo, mas UPDATER_GIT_TARGET_TAG não foi informado.');
            }

            // Busca somente a tag alvo (shallow)
            $this->shellRunner->runOrFailWithTimeout(
                ['git', 'fetch', '--prune', '--depth='.$depth, $remote, 'refs/tags/'.$tag.':refs/tags/'.$tag],
                $path,
                [],
                $timeout
            );

            $this->shellRunner->runOrFailWithTimeout(['git', 'checkout', '-f', 'tags/'.$tag], $path, [], $timeout);
        } else {
            // Fetch shallow apenas do branch alvo
            $this->shellRunner->runOrFailWithTimeout(['git', 'fetch', '--prune', '--depth='.$depth, $remote, $branch], $path, [], $timeout);
            $this->shellRunner->runOrFailWithTimeout(['git', 'checkout', '-f', $branch], $path, [], $timeout);

            if ($mode === 'ff-only' || $mode === 'ff_only') {
                $this->shellRunner->runOrFailWithTimeout(['git', 'merge', '--ff-only', $remote.'/'.$branch], $path, [], $timeout);
            } elseif ($mode === 'merge') {
                $this->shellRunner->runOrFailWithTimeout(['git', 'merge', '--no-edit', $remote.'/'.$branch], $path, [], $timeout);
            } elseif ($mode === 'full' || $mode === 'pull') {
                // Full: força o branch local igual ao remoto
                $this->shellRunner->runOrFailWithTimeout(['git', 'reset', '--hard', $remote.'/'.$branch], $path, [], $timeout);
            } else {
                throw new \RuntimeException('UPDATER_GIT_DEFAULT_UPDATE_MODE inválido: '.$mode);
            }
        }

        if ((bool) env('UPDATER_GIT_AUTO_GC', true)) {
            $this->shellRunner->runOrFailWithTimeout(['git', 'gc', '--aggressive', '--prune=now'], $path, [], $timeout);
        }

        return $this->currentRevision();
    }

    public function rollback(string $revision): void
    {
        $timeout = (int) env('UPDATER_STEP_TIMEOUT_GIT', 600);
        $depth = (int) env('UPDATER_GIT_SHALLOW_DEPTH', 50);

        $config = $this->runtimeConfig();
        $path = (string) ($config['path'] ?? $this->path);
        $remote = (string) ($config['remote'] ?? $this->remote);
        $branch = (string) ($config['branch'] ?? $this->branch);

        // Atualiza apenas o branch alvo (shallow) e volta pro revision (hard)
        $this->shellRunner->runOrFailWithTimeout(['git', 'fetch', '--prune', '--depth='.$depth, $remote, $branch], $path, $this->gitEnv(), $timeout);
        $this->shellRunner->runOrFailWithTimeout(['git', 'reset', '--hard', $revision], $path, $this->gitEnv(), $timeout);

        if ((bool) env('UPDATER_GIT_AUTO_GC', true)) {
            $this->shellRunner->runOrFailWithTimeout(['git', 'gc', '--aggressive', '--prune=now'], $path, $this->gitEnv(), $timeout);
        }
    }

    private function runtimeConfig(): array
    {
        // Config de runtime: dá prioridade ao config/updater.php, mas mantém fallback em .env
        // (mesmo com config cache) para reduzir problemas de "não pegou UPDATER_*".
        $runtime = config('updater.git', []);
        $merged = is_array($runtime) ? array_merge($this->config, $runtime) : $this->config;

        // Fallbacks via env (aplicados apenas se não estiverem definidos no config)
        $fallbacks = [
            'path'       => env('UPDATER_GIT_PATH'),
            'remote'     => env('UPDATER_GIT_REMOTE'),
            'branch'     => env('UPDATER_GIT_BRANCH'),
            'remote_url' => env('UPDATER_GIT_REMOTE_URL'),
            'update_type'=> env('UPDATER_GIT_UPDATE_TYPE'),
            'tag'        => env('UPDATER_GIT_TARGET_TAG'),
            'auto_init'  => env('UPDATER_GIT_AUTO_INIT'),
        ];

        foreach ($fallbacks as $key => $value) {
            if (!array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === '') {
                if ($value !== null && $value !== '') {
                    $merged[$key] = $value;
                }
            }
        }

        // Overrides persistidos via UI (fallback ao config/env).
        if (function_exists('app')) {
            try {
                /** @var \Argws\LaravelUpdater\Support\ManagerStore $managerStore */
                $managerStore = app(\Argws\LaravelUpdater\Support\ManagerStore::class);
                $runtime = $managerStore->runtimeSettings();
                if (isset($runtime['git']['first_run_assume_behind'])) {
                    $merged['first_run_assume_behind'] = (bool) $runtime['git']['first_run_assume_behind'];
                }
                if (isset($runtime['git']['first_run_assume_behind_commits'])) {
                    $merged['first_run_assume_behind_commits'] = max(1, (int) $runtime['git']['first_run_assume_behind_commits']);
                }
            } catch (\Throwable $e) {
                // ignora em contextos sem container/schema.
            }
        }

        // Normalizações
        if (isset($merged['auto_init'])) {
            $merged['auto_init'] = filter_var($merged['auto_init'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $merged['auto_init'];
        }

        return $merged;
    }

    private function currentTag(): ?string
    {
        $result = $this->shellRunner->run(['git', 'describe', '--tags', '--exact-match'], $this->cwd(), $this->gitEnv());
        if ($result['exit_code'] !== 0) {
            return null;
        }

        $tag = trim((string) $result['stdout']);

        return $tag !== '' ? $tag : null;
    }

    private function cwd(): string
    {
        $config = $this->runtimeConfig();
        $configuredPath = trim((string) ($config['path'] ?? ''));
        if ($configuredPath === '') {
            $configuredPath = trim((string) env('UPDATER_GIT_PATH', ''));
        }

        if ($configuredPath !== '') {
            return $configuredPath;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '.');
    }

    private function isGitRepository(): bool
    {
        $cwd = $this->cwd();
        if (!is_dir($cwd)) {
            return false;
        }

        $result = $this->shellRunner->run(['git', 'rev-parse', '--is-inside-work-tree'], $cwd);

        if ($result['exit_code'] !== 0 || trim((string) $result['stdout']) !== 'true') {
            return false;
        }

        // Repositório vazio (ex.: criado por "git init" sem commits) NÃO serve para o updater.
        // Evita "fatal: ambiguous argument 'HEAD'" e mantém a UI funcionando.
        $head = $this->shellRunner->run(['git', 'rev-parse', '--verify', 'HEAD'], $cwd);
        return $head['exit_code'] === 0;
    }

    private function tryInitRepository(array $config, string $remote, string $branch): bool
    {
        $autoInit = (bool) ($config['auto_init'] ?? false);
        $remoteUrl = trim((string) ($config['remote_url'] ?? ''));
        $cwd = $this->cwd();

        if (!$autoInit || $remoteUrl === '' || !is_dir($cwd)) {
            return false;
        }

        $env = ['GIT_TERMINAL_PROMPT' => '0'];

        // Segurança: não inicializa git dentro de uma aplicação já existente.
        $hasApp = is_file($cwd . DIRECTORY_SEPARATOR . 'artisan')
            || is_file($cwd . DIRECTORY_SEPARATOR . 'composer.json')
            || is_dir($cwd . DIRECTORY_SEPARATOR . 'vendor')
            || is_dir($cwd . DIRECTORY_SEPARATOR . 'app');
        if ($hasApp && !is_dir($cwd . DIRECTORY_SEPARATOR . '.git')) {
            // Se a instância foi deployada sem .git, só permitimos auto-init quando
		// UPDATER_GIT_AUTO_INIT_FORCE=true (ou config updater.git.auto_init_force).
		$force = (bool) (config('updater.git.auto_init_force') ?? false);
		if (!$force) {
			return false;
		}

        }

        $init = $this->shellRunner->run(['git', 'init'], $cwd, $env);
        if ($init['exit_code'] !== 0) {
            return false;
        }

        // Se o repositório acabou de ser inicializado (sem commits), o git não possui HEAD.
        // Nesse cenário, o checkout de tag/branch falha com "ambiguous argument 'HEAD'" e/ou
        // "untracked working tree files would be overwritten" porque todos os arquivos são untracked.
        //
        // Estratégia segura do updater (pois já existe snapshot/backup antes do update):
        // 1) cria um commit de bootstrap com o working tree atual
        // 2) em seguida realiza fetch/checkout forçado do alvo
        $head = $this->shellRunner->run(['git', 'rev-parse', '--verify', 'HEAD'], $cwd, $env);
        if ($head['exit_code'] !== 0) {
            $authorName = (string) (config('updater.git.bootstrap_author_name') ?? 'Updater');
            $authorEmail = (string) (config('updater.git.bootstrap_author_email') ?? 'updater@localhost');

            $this->shellRunner->run(['git', 'config', 'user.name', $authorName], $cwd, $env);
            $this->shellRunner->run(['git', 'config', 'user.email', $authorEmail], $cwd, $env);

            // Garante index consistente
            $this->shellRunner->run(['git', 'add', '-A'], $cwd, $env);
            $commit = $this->shellRunner->run(['git', 'commit', '-m', 'bootstrap working tree for updater', '--no-gpg-sign'], $cwd, $env);
            // commit pode retornar !=0 se não houver nada para commitar; nesse caso seguimos.
        }


        $this->shellRunner->run(['git', 'remote', 'remove', $remote], $cwd, $env);
        $setRemote = $this->shellRunner->run(['git', 'remote', 'add', $remote, $remoteUrl], $cwd, $env);
        if ($setRemote['exit_code'] !== 0) {
            return false;
        }

        $updateType = (string) ($config['update_type'] ?? 'git_ff_only');
        $targetTag = trim((string) ($config['tag'] ?? ''));

        if ($updateType === 'git_tag' && $targetTag !== '') {
            $fetchTags = $this->shellRunner->run(['git', 'fetch', '--tags', '--force', $remote], $cwd, $env);
            if ($fetchTags['exit_code'] !== 0) {
                return false;
            }

            $checkoutTag = $this->shellRunner->run(['git', 'checkout', '--detach', '--force', $targetTag], $cwd, $env);
            if ($checkoutTag['exit_code'] !== 0) {
                return false;
            }

            return $this->isGitRepository();
        }

        $fetch = $this->shellRunner->run(['git', 'fetch', '--depth=1', $remote, $branch], $cwd, $env);
        if ($fetch['exit_code'] !== 0) {
            $fetch = $this->shellRunner->run(['git', 'fetch', $remote, $branch], $cwd, $env);
            if ($fetch['exit_code'] !== 0) {
                return false;
            }
        }

        $checkout = $this->shellRunner->run(['git', 'checkout', '-B', $branch], $cwd, $env);
        if ($checkout['exit_code'] !== 0) {
            return false;
        }

        $reset = $this->shellRunner->run(['git', 'reset', '--hard', 'FETCH_HEAD'], $cwd, $env);
        if ($reset['exit_code'] !== 0) {
            return false;
        }

        $this->shellRunner->run(['git', 'branch', '--set-upstream-to=' . $remote . '/' . $branch, $branch], $cwd, $env);

        return $this->isGitRepository();
    }
}
