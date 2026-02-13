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

        return trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'])['stdout']);
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
            ];
        }

        $remote = $this->config['remote'];
        $branch = $this->config['branch'];
        $this->shellRunner->runOrFail(['git', 'fetch', $remote, $branch]);

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

        $result = $this->shellRunner->runOrFail(['git', 'status', '--porcelain']);

        return trim($result['stdout']) === '';
    }

    public function update(): string
    {
        if (!$this->isGitRepository()) {
            throw new GitException('Diretório atual não é um repositório git válido.');
        }

        $remote = $this->config['remote'];
        $branch = $this->config['branch'];
        $updateType = (string) ($this->config['update_type'] ?? 'git_ff_only');

        if ($updateType === 'git_tag' && !empty($this->config['tag'])) {
            $result = $this->shellRunner->run(['git', 'fetch', '--tags', $remote]);
            if ($result['exit_code'] !== 0) {
                throw new GitException($result['stderr'] ?: 'Falha ao buscar tags.');
            }
            $result = $this->shellRunner->run(['git', 'checkout', 'tags/' . (string) $this->config['tag']]);
            if ($result['exit_code'] !== 0) {
                throw new GitException($result['stderr'] ?: 'Falha ao realizar checkout da tag.');
            }

            return $this->currentRevision();
        }

        $args = ['git', 'pull', $remote, $branch];

        if ($updateType === 'git_ff_only' || (($this->config['ff_only'] ?? false) === true && $updateType !== 'git_merge')) {
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

        $result = $this->shellRunner->run(['git', 'reset', '--hard', $revision]);
        if ($result['exit_code'] !== 0) {
            throw new GitException($result['stderr'] ?: 'Falha no rollback de código via git.');
        }
    }

    private function isGitRepository(): bool
    {
        $result = $this->shellRunner->run(['git', 'rev-parse', '--is-inside-work-tree']);

        return $result['exit_code'] === 0 && trim((string) $result['stdout']) === 'true';
    }
}
