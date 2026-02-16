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

    public function currentRevision(): string
    {
        if (!$this->isGitRepository()) {
            return 'N/A';
        }

        return trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'], $this->cwd())['stdout']);
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
        $this->shellRunner->runOrFail(['git', 'fetch', $remote, $branch], $this->cwd());

        $local = trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'], $this->cwd())['stdout']);
        $remoteHead = trim($this->shellRunner->runOrFail(['git', 'rev-parse', "{$remote}/{$branch}"], $this->cwd())['stdout']);

        $ahead = (int) trim($this->shellRunner->runOrFail(['git', 'rev-list', '--count', "{$remote}/{$branch}..HEAD"], $this->cwd())['stdout']);
        $behind = (int) trim($this->shellRunner->runOrFail(['git', 'rev-list', '--count', "HEAD..{$remote}/{$branch}"], $this->cwd())['stdout']);

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
        $this->shellRunner->runOrFail(['git', 'fetch', '--tags', $remote], $this->cwd());

        $output = $this->shellRunner->runOrFail(['git', 'tag', '--list', '--sort=-version:refname'], $this->cwd())['stdout'];
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

        $result = $this->shellRunner->runOrFail(['git', 'status', '--porcelain'], $this->cwd());
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
        $config = $this->runtimeConfig();
        $remote = (string) ($config['remote'] ?? 'origin');
        $branch = (string) ($config['branch'] ?? 'main');
        $updateType = (string) ($config['update_type'] ?? 'git_ff_only');

        if (!$this->isGitRepository()) {
            if (!$this->tryInitRepository($config, $remote, $branch)) {
                $cwd = $this->cwd();
                throw new GitException('Diretório atual não é um repositório git válido: ' . $cwd . '. Defina UPDATER_GIT_PATH corretamente, limpe o cache de configuração (php artisan config:clear) e confirme permissões do usuário do PHP.');
            }
        }

        if ($updateType === 'git_tag' && !empty($config['tag'])) {
            $result = $this->shellRunner->run(['git', 'fetch', '--tags', '--force', $remote], $this->cwd());
            if ($result['exit_code'] !== 0) {
                throw new GitException($result['stderr'] ?: 'Falha ao buscar tags.');
            }
            $result = $this->shellRunner->run(['git', 'checkout', '--force', 'tags/' . (string) $config['tag']], $this->cwd());
            if ($result['exit_code'] !== 0) {
                throw new GitException($result['stderr'] ?: 'Falha ao realizar checkout da tag.');
            }

            return $this->currentRevision();
        }

        $args = ['git', 'pull', $remote, $branch];

        if ($updateType === 'git_ff_only' || (($config['ff_only'] ?? false) === true && $updateType !== 'git_merge')) {
            $args[] = '--ff-only';
        }

        $result = $this->shellRunner->run($args, $this->cwd());
        if ($result['exit_code'] !== 0) {
            throw new GitException($result['stderr'] ?: 'Falha ao atualizar código via git.');
        }

        return $this->currentRevision();
    }

    public function rollback(string $revision): void
    {
        if (!$this->isGitRepository()) {
            throw new GitException('Diretório atual não é um repositório git válido.');
        }

        $result = $this->shellRunner->run(['git', 'reset', '--hard', $revision], $this->cwd());
        if ($result['exit_code'] !== 0) {
            throw new GitException($result['stderr'] ?: 'Falha no rollback de código via git.');
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
        $result = $this->shellRunner->run(['git', 'describe', '--tags', '--exact-match'], $this->cwd());
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

        return $result['exit_code'] === 0 && trim((string) $result['stdout']) === 'true';
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

        $init = $this->shellRunner->run(['git', 'init'], $cwd, $env);
        if ($init['exit_code'] !== 0) {
            return false;
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

            $checkoutTag = $this->shellRunner->run(['git', 'checkout', '--force', 'tags/' . $targetTag], $cwd, $env);
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

