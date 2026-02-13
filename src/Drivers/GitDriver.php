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

        $local = trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'])['stdout']);
        $remoteHead = trim($this->shellRunner->runOrFail(['git', 'rev-parse', "{$remote}/{$branch}"])['stdout']);

        $ahead = (int) trim($this->shellRunner->runOrFail(['git', 'rev-list', '--count', "{$remote}/{$branch}..HEAD"])['stdout']);
        $behind = (int) trim($this->shellRunner->runOrFail(['git', 'rev-list', '--count', "HEAD..{$remote}/{$branch}"])['stdout']);

        return [
            'local' => $local,
            'remote' => $remoteHead,
            'behind_by_commits' => $behind,
            'ahead_by_commits' => $ahead,
            'has_updates' => $behind > 0,
        ];
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

        $result = $this->shellRunner->run($args);
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
