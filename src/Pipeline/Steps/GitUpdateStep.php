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

                $env = ['GIT_TERMINAL_PROMPT' => '0'];
                $cwd = (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());
                $this->shellRunner->run(['git', 'remote', 'set-url', 'origin', $url], $cwd, $env);
                $this->shellRunner->run(['git', 'fetch', 'origin', $branch], $cwd, $env);
            }

            $context['source_id'] = (int) $activeSource['id'];
            $context['source_name'] = (string) $activeSource['name'];
            $context['source_type'] = $sourceType;
        }

        $context['revision_before'] = $this->codeDriver->currentRevision();

        if ($isDryRun) {
            $context['dry_run_plan']['git'] = [
                'atual' => $context['revision_before'],
                'alvo' => $this->codeDriver->statusUpdates()['remote'] ?? null,
                'comandos' => ['git fetch origin ' . config('updater.git.branch', 'main'), 'git rev-list --count HEAD..origin/' . config('updater.git.branch', 'main')],
            ];
            $context['revision_after'] = $context['revision_before'];
            return;
        }

        $context['revision_after'] = $this->codeDriver->update();
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['revision_before']) && !(bool) ($context['options']['dry_run'] ?? false)) {
            $this->codeDriver->rollback($context['revision_before']);
        }
    }
}
