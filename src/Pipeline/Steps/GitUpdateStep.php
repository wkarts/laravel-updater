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
        if (is_array($activeSource) && ($activeSource['type'] ?? '') !== 'zip' && $this->shellRunner !== null) {
            $url = (string) ($activeSource['repo_url'] ?? '');
            if ($url !== '') {
                if (($activeSource['auth_mode'] ?? 'none') === 'token' && !empty($activeSource['token_encrypted']) && str_starts_with($url, 'https://')) {
                    $url = preg_replace('#^https://#', 'https://' . rawurlencode((string) $activeSource['token_encrypted']) . '@', $url) ?: $url;
                }
                $this->shellRunner->run(['git', 'remote', 'set-url', 'origin', $url]);
                if (!empty($activeSource['branch'])) {
                    $this->shellRunner->run(['git', 'fetch', 'origin', (string) $activeSource['branch']]);
                    $this->shellRunner->run(['git', 'checkout', (string) $activeSource['branch']]);
                }
                $context['source_id'] = (int) $activeSource['id'];
                $context['source_name'] = (string) $activeSource['name'];
            }
        }

        $context['revision_before'] = $this->codeDriver->currentRevision();
        $context['revision_after'] = $this->codeDriver->update();
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['revision_before'])) {
            $this->codeDriver->rollback($context['revision_before']);
        }
    }
}
