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
        $result = $this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD']);
        return trim($result['stdout']);
    }

    public function hasUpdates(): bool
    {
        $remote = $this->config['remote'];
        $branch = $this->config['branch'];
        $this->shellRunner->runOrFail(['git', 'fetch', $remote, $branch]);

        $local = trim($this->shellRunner->runOrFail(['git', 'rev-parse', 'HEAD'])['stdout']);
        $remoteHead = trim($this->shellRunner->runOrFail(['git', 'rev-parse', "{$remote}/{$branch}"])['stdout']);

        return $local !== $remoteHead;
    }

    public function update(): string
    {
        $remote = $this->config['remote'];
        $branch = $this->config['branch'];
        $args = ['git', 'pull', $remote, $branch];

        if (($this->config['ff_only'] ?? false) === true) {
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
        $result = $this->shellRunner->run(['git', 'reset', '--hard', $revision]);
        if ($result['exit_code'] !== 0) {
            throw new GitException($result['stderr'] ?: 'Falha no rollback de código via git.');
        }
    }
}
