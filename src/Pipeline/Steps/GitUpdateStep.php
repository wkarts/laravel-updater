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

    public function name(): string
    {
        return 'git_update';
    }

    public function shouldRun(array $context): bool
    {
        return true;
    }

    public function handle(array &$context): void
    {
        $isDryRun = (bool) ($context['options']['dry_run'] ?? false);
        $requestedUpdateType = trim((string) ($context['options']['update_type'] ?? config('updater.git.update_type', 'git_ff_only')));
        $requestedTag = trim((string) ($context['options']['target_tag'] ?? config('updater.git.tag', '')));

        $cwd = (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());
        $env = ['GIT_TERMINAL_PROMPT' => '0'];

        $context['cwd'] = $cwd;
        $context['git_update_log'][] = sprintf('tipo solicitado: %s', $requestedUpdateType);
        if ($requestedTag !== '') {
            $context['git_update_log'][] = sprintf('tag solicitada: %s', $requestedTag);
        }

        config(['updater.git.update_type' => $requestedUpdateType]);
        if ($requestedTag !== '') {
            config(['updater.git.tag' => $requestedTag]);
        }

        $this->prepareSourceContext($context, $requestedUpdateType, $requestedTag, $cwd, $env, $isDryRun);

        if (!$isDryRun && $this->shellRunner !== null) {
            $this->autoStashWorkingTree($context, $cwd, $env);
        }

        $context['revision_before'] = $this->codeDriver->currentRevision();
        $context['git_tag_before'] = $this->resolveCurrentTag();

        if (!$isDryRun) {
            $this->backupDotEnv($context);
        }

        if ($isDryRun) {
            $status = $this->codeDriver->statusUpdates();
            $branch = (string) config('updater.git.branch', 'main');
            $context['dry_run_plan']['git'] = [
                'atual' => $context['revision_before'],
                'alvo' => $status['latest_tag'] ?? $status['remote'] ?? null,
                'comandos' => ['git fetch origin ' . $branch, 'git rev-list --count HEAD..origin/' . $branch],
            ];
            $context['revision_after'] = $context['revision_before'];
            return;
        }

        try {
            $context['revision_after'] = $this->codeDriver->update();
        } catch (\Throwable $e) {
            if ($this->shellRunner === null || !$this->shouldRetryAfterUntrackedOverwrite($e)) {
                throw $e;
            }

            if ($requestedUpdateType === 'git_tag') {
                $this->forceCleanUntrackedForCheckout($context, $cwd, $env);
                $context['git_update_log'][] = 'Conflitos de untracked resolvidos com clean controlado para checkout de tag.';
            } else {
                $this->quarantineUntrackedOverwriteFiles($context, $e->getMessage(), $cwd, $env);
                $context['git_update_log'][] = 'Arquivos untracked conflitantes movidos para quarentena antes do retry.';
            }

            $context['revision_after'] = $this->codeDriver->update();
        } finally {
            $this->restoreDotEnv($context);
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

            if (!$allowNoChange && $hadPreviousRevision && !$alreadyAtRequestedTag) {
                throw new \RuntimeException('Nenhuma atualização real foi aplicada (revision_before == revision_after). Cancelando execução para evitar falso sucesso.');
            }
        }

        $context['git_update_log'][] = sprintf('revision_before: %s', (string) ($context['revision_before'] ?? 'N/A'));
        $context['git_update_log'][] = sprintf('revision_after: %s', (string) ($context['revision_after'] ?? 'N/A'));

        $this->pruneRepositoryAfterUpdate($cwd, $env, $requestedUpdateType);
    }

    public function rollback(array &$context): void
    {
        if (!empty($context['revision_before']) && !(bool) ($context['options']['dry_run'] ?? false)) {
            $this->codeDriver->rollback($context['revision_before']);
        }

        $this->restoreDotEnv($context);
    }

    private function prepareSourceContext(array &$context, string $requestedUpdateType, string $requestedTag, string $cwd, array $env, bool $isDryRun): void
    {
        $activeSource = $this->managerStore?->activeSource();
        if (!is_array($activeSource) || $this->shellRunner === null) {
            return;
        }

        $sourceType = (string) ($activeSource['type'] ?? 'git_ff_only');
        $url = (string) ($activeSource['repo_url'] ?? '');
        $branch = (string) ($activeSource['branch'] ?? config('updater.git.branch', 'main'));

        if ($url !== '' && !$isDryRun) {
            $url = $this->injectSourceCredentials($activeSource, $url);
            config(['updater.git.remote_url' => $url, 'updater.git.branch' => $branch]);

            if (!$this->isGitRepository($cwd, $env)) {
                $autoInit = (bool) config('updater.git.auto_init', false);
                $forceBootstrap = (bool) ($context['options']['force'] ?? false);

                if (!$autoInit && !$forceBootstrap) {
                    throw new \RuntimeException('Repositório git ausente em ' . $cwd . '. Habilite UPDATER_GIT_AUTO_INIT=true ou execute com --force para bootstrap automático.');
                }

                $this->bootstrapRepository($context, $cwd, $url, $branch, $requestedUpdateType, $requestedTag, $env);
                $context['git_bootstrapped'] = true;
            } else {
                $this->shellRunner->runOrFail(['git', 'remote', 'set-url', 'origin', $url], $cwd, $env);

                if ($requestedUpdateType === 'git_tag' && $requestedTag !== '') {
                    $depth = max(1, (int) config('updater.git.tag_fetch_depth', 1));
                    $this->shellRunner->run(['git', 'fetch', '--depth=' . $depth, 'origin', 'tag', $requestedTag], $cwd, $env);
                } else {
                    $this->shellRunner->runOrFail(['git', 'fetch', '--prune', '--depth=1', 'origin', $branch], $cwd, $env);
                }
            }
        }

        $context['source_id'] = (int) ($activeSource['id'] ?? 0);
        $context['source_name'] = (string) ($activeSource['name'] ?? '');
        $context['source_type'] = $sourceType;
    }

    private function injectSourceCredentials(array $source, string $url): string
    {
        $authMode = (string) ($source['auth_mode'] ?? 'none');
        $username = trim((string) ($source['auth_username'] ?? ''));
        $password = trim((string) ($source['auth_password'] ?? $source['token_encrypted'] ?? ''));

        if ($authMode !== 'token' || $password === '' || !str_starts_with($url, 'https://')) {
            return $url;
        }

        if ($username !== '') {
            return preg_replace('#^https://#', 'https://' . rawurlencode($username) . ':' . rawurlencode($password) . '@', $url) ?: $url;
        }

        return preg_replace('#^https://#', 'https://' . rawurlencode($password) . '@', $url) ?: $url;
    }

    private function autoStashWorkingTree(array &$context, string $cwd, array $env = []): void
    {
        $allowDirty = (bool) ($context['options']['allow_dirty'] ?? false);
        $autoStash = (bool) ($context['options']['auto_stash'] ?? config('updater.git.auto_stash', true));

        if ($this->shellRunner === null) {
            return;
        }

        $status = $this->shellRunner->run(['git', 'status', '--porcelain'], $cwd, $env);
        $porcelain = trim((string) ($status['stdout'] ?? ''));

        $untracked = $this->shellRunner->run(['git', 'ls-files', '--others', '--exclude-standard'], $cwd, $env);
        $hasUntracked = trim((string) ($untracked['stdout'] ?? '')) !== '';

        if ($porcelain === '' && !$hasUntracked) {
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
            $this->cleanupOldEnvBackups($storage, 20);
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
            @unlink($backupPath);
        }
    }

    private function cleanupOldEnvBackups(string $storage, int $keep): void
    {
        $files = glob(rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'env_backup_*.env') ?: [];
        if (count($files) <= $keep) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
        }
    }

    private function resolveCurrentTag(): ?string
    {
        $cwd = (string) config('updater.git.path', function_exists('base_path') ? base_path() : getcwd());
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        if (!$this->isGitRepository($cwd, $env)) {
            return null;
        }

        if ($this->shellRunner === null) {
            return null;
        }

        $result = $this->shellRunner->run(['git', 'describe', '--tags', '--exact-match'], $cwd, $env);
        if (!is_array($result) || (int) ($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        $tag = trim((string) ($result['stdout'] ?? ''));
        return $tag !== '' ? $tag : null;
    }

    private function isGitRepository(string $cwd, array $env): bool
    {
        if ($this->shellRunner === null) {
            return false;
        }

        $result = $this->shellRunner->run(['git', 'rev-parse', '--is-inside-work-tree'], $cwd, $env);

        if (!is_array($result)
            || (int) ($result['exit_code'] ?? 1) !== 0
            || trim((string) ($result['stdout'] ?? '')) !== 'true') {
            return false;
        }

        $head = $this->shellRunner->run(['git', 'rev-parse', '--verify', 'HEAD'], $cwd, $env);
        return is_array($head) && (int) ($head['exit_code'] ?? 1) === 0;
    }

    private function bootstrapRepository(array &$context, string $cwd, string $url, string $branch, string $requestedUpdateType, string $requestedTag, array $env): void
    {
        if ($this->shellRunner === null) {
            throw new \RuntimeException('ShellRunner não disponível para bootstrap do repositório.');
        }

        $hasApp = is_file($cwd . DIRECTORY_SEPARATOR . 'artisan')
            || is_file($cwd . DIRECTORY_SEPARATOR . 'composer.json')
            || is_dir($cwd . DIRECTORY_SEPARATOR . 'vendor')
            || is_dir($cwd . DIRECTORY_SEPARATOR . 'app');

        if ($hasApp && !$this->isGitRepository($cwd, $env)) {
            if ($requestedUpdateType !== 'git_tag') {
                throw new \RuntimeException('Diretório não é um repositório git válido (sem .git ou sem HEAD). Para modos branch/merge é obrigatório deploy com clone git.');
            }

            if (trim($url) === '' || trim($requestedTag) === '') {
                throw new \RuntimeException('Bootstrap git para update_type=git_tag requer repo_url e target_tag.');
            }
        }

        $this->shellRunner->runOrFail(['git', 'init'], $cwd, $env);
        $this->shellRunner->run(['git', 'remote', 'remove', 'origin'], $cwd, $env);
        $this->shellRunner->runOrFail(['git', 'remote', 'add', 'origin', $url], $cwd, $env);

        if ($requestedUpdateType === 'git_tag' && $requestedTag !== '') {
            $depth = max(1, (int) config('updater.git.tag_fetch_depth', 1));
            $this->shellRunner->runOrFail(['git', 'fetch', '--depth=' . $depth, 'origin', 'tag', $requestedTag], $cwd, $env);
            try {
                $this->shellRunner->runOrFail(['git', 'checkout', '--detach', $requestedTag], $cwd, $env);
            } catch (\Throwable $e) {
                if (!$this->shouldRetryAfterUntrackedOverwrite($e)) {
                    throw $e;
                }

                $context['git_update_log'][] = 'Bootstrap/tag detectou conflito de untracked no checkout; aplicando limpeza controlada e retry.';
                $this->forceCleanUntrackedForCheckout($context, $cwd, $env);
                $this->quarantineUntrackedOverwriteFiles($context, $e->getMessage(), $cwd, $env);
                $this->shellRunner->runOrFail(['git', 'checkout', '--detach', '-f', $requestedTag], $cwd, $env);
            }
            $this->shellRunner->runOrFail(['git', 'reset', '--hard'], $cwd, $env);
            return;
        }

        $this->shellRunner->runOrFail(['git', 'fetch', '--prune', '--depth=1', 'origin', $branch], $cwd, $env);
        $this->shellRunner->runOrFail(['git', 'checkout', '-B', $branch], $cwd, $env);
        $this->shellRunner->runOrFail(['git', 'reset', '--hard', 'FETCH_HEAD'], $cwd, $env);
        $this->shellRunner->run(['git', 'branch', '--set-upstream-to=origin/' . $branch, $branch], $cwd, $env);
    }

    private function shouldRetryAfterUntrackedOverwrite(\Throwable $e): bool
    {
        $msg = mb_strtolower($e->getMessage());
        return str_contains($msg, 'untracked working tree files would be overwritten')
            || str_contains($msg, 'would be overwritten by merge')
            || str_contains($msg, 'please move or remove them before you merge')
            || str_contains($msg, 'would be overwritten by checkout');
    }

    private function quarantineUntrackedOverwriteFiles(array &$context, string $message, string $cwd, array $env): void
    {
        $files = [];
        foreach (preg_split('/\r?\n/', $message) as $line) {
            if ($line === '' || !str_starts_with($line, "\t")) {
                continue;
            }
            $p = trim(ltrim($line, "\t"));
            if ($p !== '' && !$this->isProtectedPath($p)) {
                $files[] = $p;
            }
        }

        if (empty($files)) {
            return;
        }

        $runId = (string) ($context['run_id'] ?? 'manual');
        $stamp = date('Ymd_His');
        $quarantineBase = function_exists('storage_path')
            ? storage_path('app/updater/quarantine')
            : ($cwd . DIRECTORY_SEPARATOR . 'storage/app/updater/quarantine');

        if (!is_dir($quarantineBase)) {
            @mkdir($quarantineBase, 0775, true);
        }

        $destDir = rtrim($quarantineBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'run_' . $runId . '_' . $stamp;
        @mkdir($destDir, 0775, true);

        foreach (array_values(array_unique($files)) as $rel) {
            $src = $cwd . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (!file_exists($src)) {
                continue;
            }

            $dst = $destDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            $dstParent = dirname($dst);
            if (!is_dir($dstParent)) {
                @mkdir($dstParent, 0775, true);
            }

            if (!@rename($src, $dst)) {
                $this->copyDir($src, $dst);
                $this->deleteDir($src);
            }

            $context['git_quarantined_files'][] = $rel;
        }

        if ($this->shellRunner !== null) {
            $this->shellRunner->run(['git', 'clean', '-fd', '-e', '.env', '-e', '.env.*', '-e', 'storage/', '-e', 'public/storage/'], $cwd, $env);
        }
    }

    private function isProtectedPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', ltrim($path, '/'));
        return $normalized === '.env'
            || str_starts_with($normalized, '.env.')
            || str_starts_with($normalized, 'storage/')
            || str_starts_with($normalized, 'public/storage/')
            || str_starts_with($normalized, 'public/uploads/');
    }

    private function copyDir(string $src, string $dst): void
    {
        if (is_dir($src) && !is_link($src)) {
            @mkdir($dst, 0775, true);
            $items = @scandir($src);
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->copyDir($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item);
            }
            return;
        }

        @copy($src, $dst);
    }

    private function deleteDir(string $dir): void
    {
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }

        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteDir($dir . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($dir);
    }

    private function pruneRepositoryAfterUpdate(string $cwd, array $env, string $updateType): void
    {
        if ($this->shellRunner === null) {
            return;
        }

        if (!(bool) config('updater.git_maintenance.enabled', true)) {
            return;
        }

        $this->shellRunner->run(['git', 'remote', 'prune', 'origin'], $cwd, $env);

        $pruneLocalBranches = (bool) config('updater.git.prune_local_branches', false);
        if ($pruneLocalBranches && $updateType !== 'git_tag') {
            $this->pruneLocalBranches($cwd, $env);
        }

        if ($updateType === 'git_tag') {
            $this->shellRunner->run(['git', 'for-each-ref', '--format=%(refname)', 'refs/remotes/origin'], $cwd, $env);
        }

        $this->shellRunner->run(['git', 'reflog', 'expire', '--expire=now', '--all'], $cwd, $env);
        $this->shellRunner->run(['git', 'gc', '--prune=now'], $cwd, $env);
    }

    private function pruneLocalBranches(string $cwd, array $env): void
    {
        if ($this->shellRunner === null) {
            return;
        }

        $current = $this->shellRunner->run(['git', 'branch', '--show-current'], $cwd, $env);
        $currentBranch = trim((string) ($current['stdout'] ?? ''));
        if ($currentBranch === '') {
            return;
        }

        $branches = $this->shellRunner->run(['git', 'for-each-ref', '--format=%(refname:short)', 'refs/heads'], $cwd, $env);
        $stdout = (string) ($branches['stdout'] ?? '');
        $parts = preg_split('/\r\n|\r|\n/', $stdout);
        if (!is_array($parts)) {
            return;
        }

        foreach ($parts as $part) {
            $branch = trim((string) $part);
            if ($branch === '' || $branch === $currentBranch) {
                continue;
            }

            $this->shellRunner->run(['git', 'branch', '-D', $branch], $cwd, $env);
        }

        $excludes = array_values(array_unique($excludes));
        $args = ['git', 'clean', '-fd'];
        foreach ($excludes as $exclude) {
            $args[] = '-e';
            $args[] = $exclude;
        }

        $this->shellRunner->runOrFailWithTimeout($args, $cwd, $env, 600);
        $context['git_update_log'][] = 'git clean controlado executado (preservando .env/storage/uploads).';
    }

    private function forceCleanUntrackedForCheckout(array &$context, string $cwd, array $env): void
    {
        if ($this->shellRunner === null) {
            return;
        }

        $excludes = [
            '.env',
            '.env.*',
            'storage/',
            'public/storage/',
            'public/uploads/',
            'bootstrap/cache/',
        ];

        foreach ($this->parseExtraCleanExcludes((string) env('UPDATER_GIT_CLEAN_EXCLUDES', '')) as $exclude) {
            $excludes[] = $exclude;
        }

        $excludes = array_values(array_unique($excludes));
        $args = ['git', 'clean', '-fd'];
        foreach ($excludes as $exclude) {
            $args[] = '-e';
            $args[] = $exclude;
        }

        $this->shellRunner->runOrFailWithTimeout($args, $cwd, $env, 600);
        $context['git_update_log'][] = 'git clean controlado executado (preservando .env/storage/uploads).';
    }


    private function parseExtraCleanExcludes(string $raw): array
    {
        $extra = trim($raw);
        if ($extra === '') {
            return [];
        }

        $extraLines = preg_split('/;;|\r\n|\r|\n/', $extra);
        if (!is_array($extraLines)) {
            return [];
        }

        $output = [];
        foreach ($extraLines as $line) {
            $line = trim((string) $line);
            if ($line === '' || substr($line, 0, 1) === '#') {
                continue;
            }

            $output[] = $line;
        }

        return $output;
    }

}
