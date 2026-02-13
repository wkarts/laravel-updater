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
            return [
                'local' => 'N/A',
                'remote' => 'N/A',
                'behind_by_commits' => 0,
                'ahead_by_commits' => 0,
                'has_updates' => false,
                'latest_tag' => null,
                'has_update_by_tag' => false,
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

        $result = $this->shellRunner->runOrFail(['git', 'status', '--porcelain'], $this->cwd());

        return trim($result['stdout']) === '';
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
            $result = $this->shellRunner->run(['git', 'fetch', '--tags', $remote], $this->cwd());
            if ($result['exit_code'] !== 0) {
                throw new GitException($result['stderr'] ?: 'Falha ao buscar tags.');
            }
            $result = $this->shellRunner->run(['git', 'checkout', 'tags/' . (string) $config['tag']], $this->cwd());
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
        $runtime = config('updater.git', []);

        return is_array($runtime) ? array_merge($this->config, $runtime) : $this->config;
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

