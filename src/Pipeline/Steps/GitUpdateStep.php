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
        $requestedUpdateType = (string) ($context['options']['update_type'] ?? config('updater.git.update_type', 'git_ff_only'));
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

                    $this->bootstrapRepository($cwd, $url, $branch, $env);
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

        $context['revision_before'] = $this->codeDriver->currentRevision();
        $context['git_tag_before'] = $this->resolveCurrentTag();

        if (!$isDryRun) {
            $this->backupDotEnv($context);
        }

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

        $context['revision_after'] = $this->codeDriver->update();
        $context['git_tag_after'] = $this->resolveCurrentTag();

        if ($requestedUpdateType === 'git_tag' && $requestedTag !== '') {
            $afterTag = (string) ($context['git_tag_after'] ?? '');
            if ($afterTag === '' || $afterTag !== $requestedTag) {
                throw new \RuntimeException('A tag alvo não foi aplicada corretamente. Esperado: ' . $requestedTag . '; atual: ' . ($afterTag !== '' ? $afterTag : 'sem tag exata'));
            }
        }

        if (($context['revision_before'] ?? null) === ($context['revision_after'] ?? null)) {
            $context['git_update_warning'] = 'Revisão inalterada após update. Isso pode ocorrer se já estava na versão/tag alvo.';
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

        return is_array($result)
            && (int) ($result['exit_code'] ?? 1) === 0
            && trim((string) ($result['stdout'] ?? '')) === 'true';
    }

    /** @param array<string,string> $env */
    private function bootstrapRepository(string $cwd, string $url, string $branch, array $env): void
    {
        $this->shellRunner?->runOrFail(['git', 'init'], $cwd, $env);
        $this->shellRunner?->run(['git', 'remote', 'remove', 'origin'], $cwd, $env);
        $this->shellRunner?->runOrFail(['git', 'remote', 'add', 'origin', $url], $cwd, $env);

        $fetch = $this->shellRunner?->run(['git', 'fetch', '--depth=1', 'origin', $branch], $cwd, $env);
        if (!is_array($fetch) || (int) ($fetch['exit_code'] ?? 1) !== 0) {
            $this->shellRunner?->runOrFail(['git', 'fetch', 'origin', $branch], $cwd, $env);
        }

        $this->shellRunner?->runOrFail(['git', 'checkout', '-B', $branch], $cwd, $env);
        $this->shellRunner?->runOrFail(['git', 'reset', '--hard', 'FETCH_HEAD'], $cwd, $env);
        $this->shellRunner?->run(['git', 'branch', '--set-upstream-to=origin/' . $branch, $branch], $cwd, $env);
    }
}
