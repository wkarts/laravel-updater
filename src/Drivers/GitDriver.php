<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Drivers;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Exceptions\GitException;
use Argws\LaravelUpdater\Exceptions\UpdaterException;
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
        $this->preloadExceptionClasses();

        $config = $this->runtimeConfig();
        $path = $this->cwd();
        $remote = (string) ($config['remote'] ?? 'origin');
        $branch = (string) ($config['branch'] ?? 'main');
        $updateType = strtolower((string) ($config['update_type'] ?? config('updater.git.update_type', 'git_ff_only')));
        $mode = strtolower((string) config('updater.git.default_update_mode', env('UPDATER_GIT_DEFAULT_UPDATE_MODE', 'merge')));
        $tag = trim((string) ($config['tag'] ?? config('updater.git.tag', env('UPDATER_GIT_TARGET_TAG', ''))));

        $timeout = (int) env('UPDATER_STEP_TIMEOUT_GIT', 600);
        $depth = (int) env('UPDATER_GIT_SHALLOW_DEPTH', 50);

        $shouldPrune = (bool) env('UPDATER_GIT_AUTO_PRUNE', true);
        $selfPackageBackup = $this->backupSelfPackageSnapshot();
        $vendorBackup = $this->backupVendorSnapshot($path);

        // NUNCA substituir o .env atual.
        // Mesmo em tag/branch que contenha arquivos semelhantes, a aplicação SEMPRE deve reaproveitar o .env atual.
        $this->preserveEnvFile($path);

        try {
            // Nunca baixar todos os branches: fetch somente do branch/tag alvo
            if ($updateType === 'git_tag' || $mode === 'tag') {
                if ($tag === '') {
                    throw new \RuntimeException('Modo tag ativo, mas UPDATER_GIT_TARGET_TAG não foi informado.');
                }

                $tagFetchArgs = ['git', 'fetch', '--depth=' . $depth];
                if ($shouldPrune) {
                    $tagFetchArgs[] = '--prune';
                }
                $tagFetchArgs[] = $remote;
                $tagFetchArgs[] = 'refs/tags/' . $tag . ':refs/tags/' . $tag;

                // Busca somente a tag alvo (shallow), evitando fetch amplo de refs.
                $this->shellRunner->runOrFailWithTimeout($tagFetchArgs, $path, $this->gitEnv(), $timeout);

                // Se existirem arquivos untracked que conflitam com o conteúdo do tag, o checkout aborta.
                // Ex.: "untracked working tree files would be overwritten by checkout".
                $this->quarantineUntrackedConflicts('tags/' . $tag, $path, $timeout);

                $this->shellRunner->runOrFailWithTimeout(['git', 'checkout', '-f', 'tags/' . $tag], $path, $this->gitEnv(), $timeout);
                $this->restoreSelfPackageIfMissing($selfPackageBackup);
                $this->restoreVendorIfCriticalMissing($path, $vendorBackup);
            } else {
                // Fetch shallow apenas do branch alvo, sem trazer tags desnecessárias.
                $branchFetchArgs = ['git', 'fetch', '--no-tags', '--depth=' . $depth];
                if ($shouldPrune) {
                    $branchFetchArgs[] = '--prune';
                }
                $branchFetchArgs[] = $remote;
                $branchFetchArgs[] = $branch;
                $this->shellRunner->runOrFailWithTimeout($branchFetchArgs, $path, $this->gitEnv(), $timeout);

                // Evita aborto por untracked conflitantes com o branch remoto
                $this->quarantineUntrackedConflicts($remote . '/' . $branch, $path, $timeout);

                $this->shellRunner->runOrFailWithTimeout(['git', 'checkout', '-f', $branch], $path, $this->gitEnv(), $timeout);
                $this->restoreSelfPackageIfMissing($selfPackageBackup);
                $this->restoreVendorIfCriticalMissing($path, $vendorBackup);

                if ($mode === 'ff-only' || $mode === 'ff_only') {
                    $this->shellRunner->runOrFailWithTimeout(['git', 'merge', '--ff-only', $remote . '/' . $branch], $path, $this->gitEnv(), $timeout);
                    $this->restoreSelfPackageIfMissing($selfPackageBackup);
                    $this->restoreVendorIfCriticalMissing($path, $vendorBackup);
                } elseif ($mode === 'merge') {
                    $this->shellRunner->runOrFailWithTimeout(['git', 'merge', '--no-edit', $remote . '/' . $branch], $path, $this->gitEnv(), $timeout);
                    $this->restoreSelfPackageIfMissing($selfPackageBackup);
                    $this->restoreVendorIfCriticalMissing($path, $vendorBackup);
                } elseif ($mode === 'full' || $mode === 'pull') {
                    // Full: força o branch local igual ao remoto
                    $this->shellRunner->runOrFailWithTimeout(['git', 'reset', '--hard', $remote . '/' . $branch], $path, $this->gitEnv(), $timeout);
                    $this->restoreSelfPackageIfMissing($selfPackageBackup);
                    $this->restoreVendorIfCriticalMissing($path, $vendorBackup);
                } else {
                    throw new \RuntimeException('UPDATER_GIT_DEFAULT_UPDATE_MODE inválido: ' . $mode);
                }
            }

            if ((bool) env('UPDATER_GIT_AUTO_GC', true)) {
                // Evita operações agressivas longas durante update (pode parecer loop infinito em produção).
                $this->shellRunner->runOrFailWithTimeout(['git', 'gc', '--prune=now'], $path, $this->gitEnv(), $timeout);
            }

            return $this->currentRevision();
        } catch (\Throwable $e) {
            // Garante que o próprio updater continue carregável para registrar falha corretamente.
            $this->restoreSelfPackageIfMissing($selfPackageBackup);
            $this->restoreVendorIfCriticalMissing($path, $vendorBackup);
            throw $e;
        } finally {
            // Restaura o .env original SEMPRE (sucesso ou falha).
            $this->restoreEnvFile($path);
            $this->cleanupSelfPackageSnapshot($selfPackageBackup);
            $this->cleanupVendorSnapshot($vendorBackup);
        }
    }

    /** @var string|null */
    private ?string $envBackupPath = null;

    /**
     * Faz backup do .env para que NUNCA seja substituído por checkout/reset.
     */
    private function preserveEnvFile(string $cwd): void
    {
        $envPath = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            $this->envBackupPath = null;
            return;
        }

        $tmpDir = function_exists('storage_path')
            ? storage_path('app/updater/tmp')
            : rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.updater' . DIRECTORY_SEPARATOR . 'tmp';

        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $tmp = $tmpDir . DIRECTORY_SEPARATOR . 'env_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        @copy($envPath, $tmp);
        $this->envBackupPath = is_file($tmp) ? $tmp : null;
    }

    /**
     * Restaura o .env original (caso exista backup).
     */
    private function restoreEnvFile(string $cwd): void
    {
        if (!$this->envBackupPath || !is_file($this->envBackupPath)) {
            return;
        }
        $envPath = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        @copy($this->envBackupPath, $envPath);
        @unlink($this->envBackupPath);
        $this->envBackupPath = null;
    }

    /**
     * Move para quarentena SOMENTE arquivos/pastas untracked que conflitam com o alvo.
     * Isso resolve o erro do git ao trocar para tag/branch em ambientes "sujos".
     */
    private function quarantineUntrackedConflicts(string $ref, string $cwd, int $timeout): void
    {
        $untracked = (string) ($this->shellRunner->runOrFailWithTimeout(['git', 'ls-files', '--others', '--exclude-standard'], $cwd, [], $timeout)['stdout'] ?? '');
        $untracked = trim($untracked);
        if ($untracked === '') {
            return;
        }

        $untrackedList = array_values(array_filter(array_map('trim', preg_split('/\R/', $untracked))));
        if (!$untrackedList) {
            return;
        }

        $tracked = (string) ($this->shellRunner->runOrFailWithTimeout(['git', 'ls-tree', '-r', '--name-only', $ref], $cwd, [], $timeout)['stdout'] ?? '');
        $tracked = trim($tracked);
        if ($tracked === '') {
            return;
        }
        $trackedSet = array_flip(array_values(array_filter(array_map('trim', preg_split('/\R/', $tracked)))));

        $conflicts = [];
        foreach ($untrackedList as $path) {
            if ($this->isProtectedRuntimePath($path)) {
                continue;
            }
            if (isset($trackedSet[$path])) {
                $conflicts[] = $path;
            }
        }
        if (!$conflicts) {
            return;
        }

        $base = function_exists('storage_path')
            ? storage_path('app/updater/untracked')
            : rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.updater' . DIRECTORY_SEPARATOR . 'untracked';

        $runDir = $base . DIRECTORY_SEPARATOR . 'run_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        @mkdir($runDir, 0775, true);

        foreach ($conflicts as $rel) {
            $src = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
            if (!file_exists($src)) {
                continue;
            }

            $dst = $runDir . DIRECTORY_SEPARATOR . $rel;
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) {
                @mkdir($dstDir, 0775, true);
            }

            if (!@rename($src, $dst)) {
                $this->copyRecursive($src, $dst);
                $this->removeRecursive($src);
            }
        }
    }

    private function isProtectedRuntimePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', ltrim(trim($path), '/'));
        if ($normalized === '') {
            return true;
        }

        if ($normalized === '.env' || str_starts_with($normalized, '.env.')) {
            return true;
        }

        if ($normalized === 'vendor' || str_starts_with($normalized, 'vendor/')) {
            return true;
        }

        if ($normalized === 'storage' || str_starts_with($normalized, 'storage/')) {
            return true;
        }

        if ($normalized === 'public' || str_starts_with($normalized, 'public/')) {
            return true;
        }

        if ($normalized === 'bootstrap/cache' || str_starts_with($normalized, 'bootstrap/cache/')) {
            return true;
        }

        if ($normalized === 'node_modules' || str_starts_with($normalized, 'node_modules/')) {
            return true;
        }

        return false;
    }

    private function copyRecursive(string $src, string $dst): void
    {
        if (is_dir($src) && !is_link($src)) {
            @mkdir($dst, 0775, true);
            $items = scandir($src) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->copyRecursive($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item);
            }
            return;
        }
        @copy($src, $dst);
    }

    private function removeRecursive(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $items = scandir($path) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->removeRecursive($path . DIRECTORY_SEPARATOR . $item);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }

    private function backupSelfPackageSnapshot(): ?array
    {
        $packageRoot = dirname(__DIR__, 2);
        if (!is_dir($packageRoot)) {
            return null;
        }

        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/updater-self-' . uniqid('', true);
        @mkdir($tmpDir, 0775, true);
        $snapshotRoot = $tmpDir . '/laravel-updater';
        $this->copyRecursive($packageRoot, $snapshotRoot);

        if (!is_dir($snapshotRoot)) {
            return null;
        }

        return [
            'target' => $packageRoot,
            'snapshot' => $snapshotRoot,
            'tmp' => $tmpDir,
            'sentinel' => 'src/Exceptions/UpdaterException.php',
        ];
    }

    private function restoreSelfPackageIfMissing(?array $snapshot): void
    {
        if (!is_array($snapshot)) {
            return;
        }

        $target = (string) ($snapshot['target'] ?? '');
        $source = (string) ($snapshot['snapshot'] ?? '');
        $sentinel = trim((string) ($snapshot['sentinel'] ?? ''));
        if ($target === '' || $source === '' || !is_dir($source)) {
            return;
        }

        $targetExists = is_dir($target);
        $sentinelHealthy = $sentinel !== ''
            ? is_file(rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sentinel))
            : $targetExists;

        // Restaura tanto ausência total quanto deleção parcial do pacote.
        if ($targetExists && $sentinelHealthy) {
            return;
        }

        @mkdir(dirname($target), 0775, true);
        $this->copyRecursive($source, $target);

        // Segunda tentativa defensiva para casos de IO intermitente.
        if ($sentinel !== '') {
            $sentinelPath = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sentinel);
            if (!is_file($sentinelPath)) {
                $this->copyRecursive($source, $target);
            }
        }
    }

    private function cleanupSelfPackageSnapshot(?array $snapshot): void
    {
        if (!is_array($snapshot)) {
            return;
        }

        $tmp = (string) ($snapshot['tmp'] ?? '');
        if ($tmp !== '') {
            $this->removeRecursive($tmp);
        }
    }

    private function backupVendorSnapshot(string $cwd): ?array
    {
        $vendorPath = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor';
        if (!is_dir($vendorPath)) {
            return null;
        }

        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/updater-vendor-' . uniqid('', true);
        @mkdir($tmpDir, 0775, true);
        $snapshot = $tmpDir . '/vendor';
        $this->copyRecursive($vendorPath, $snapshot);

        if (!is_dir($snapshot)) {
            return null;
        }

        return [
            'target' => $vendorPath,
            'snapshot' => $snapshot,
            'tmp' => $tmpDir,
            'sentinel' => 'autoload.php',
            'updater_sentinel' => 'argws/laravel-updater/src/Exceptions/UpdaterException.php',
        ];
    }

    private function restoreVendorIfCriticalMissing(string $cwd, ?array $vendorSnapshot): void
    {
        if (!is_array($vendorSnapshot)) {
            return;
        }

        $target = (string) ($vendorSnapshot['target'] ?? '');
        $source = (string) ($vendorSnapshot['snapshot'] ?? '');
        if ($target === '' || $source === '' || !is_dir($source)) {
            return;
        }

        $autoload = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($vendorSnapshot['sentinel'] ?? 'autoload.php'));
        $updaterSentinel = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($vendorSnapshot['updater_sentinel'] ?? 'argws/laravel-updater/src/Exceptions/UpdaterException.php'));

        if (is_dir($target) && is_file($autoload) && is_file($updaterSentinel)) {
            return;
        }

        @mkdir(dirname($target), 0775, true);
        $this->copyRecursive($source, $target);
    }

    private function cleanupVendorSnapshot(?array $vendorSnapshot): void
    {
        if (!is_array($vendorSnapshot)) {
            return;
        }

        $tmp = (string) ($vendorSnapshot['tmp'] ?? '');
        if ($tmp !== '') {
            $this->removeRecursive($tmp);
        }
    }

    public function rollback(string $revision): void
    {
        $this->preloadExceptionClasses();

        $timeout = (int) env('UPDATER_STEP_TIMEOUT_GIT', 600);
        $depth = (int) env('UPDATER_GIT_SHALLOW_DEPTH', 50);

        $config = $this->runtimeConfig();
        $path = $this->cwd();
        $remote = (string) ($config['remote'] ?? 'origin');
        $branch = (string) ($config['branch'] ?? 'main');

        // Atualiza apenas o branch alvo (shallow) e volta pro revision (hard)
        $this->shellRunner->runOrFailWithTimeout(['git', 'fetch', '--no-tags', '--prune', '--depth=' . $depth, $remote, $branch], $path, $this->gitEnv(), $timeout);
        $this->shellRunner->runOrFailWithTimeout(['git', 'reset', '--hard', $revision], $path, $this->gitEnv(), $timeout);

        if ((bool) env('UPDATER_GIT_AUTO_GC', true)) {
            $this->shellRunner->runOrFailWithTimeout(['git', 'gc', '--prune=now'], $path, $this->gitEnv(), $timeout);
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

    private function preloadExceptionClasses(): void
    {
        // Em alguns cenários de checkout/clean agressivo do host, o autoloader pode tentar
        // carregar exceções do próprio pacote após arquivos temporariamente indisponíveis.
        // Forçamos o preload aqui para evitar fatal por "Failed to open stream" durante git_update.
        class_exists(UpdaterException::class);
        class_exists(GitException::class);
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
        $autoDetect = (bool) ($config['auto_detect_path'] ?? true);

        if ($configuredPath !== '') {
            $realConfigured = @realpath($configuredPath);
            if (is_string($realConfigured) && $realConfigured !== '' && is_dir($realConfigured)) {
                return $realConfigured;
            }

            if (!$autoDetect) {
                return $configuredPath;
            }
        }

        $base = function_exists('base_path') ? (string) base_path() : '';
        if ($base !== '' && is_dir($base)) {
            return $base;
        }

        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($script !== '') {
            $scriptDir = dirname($script);
            if (is_dir($scriptDir)) {
                return $scriptDir;
            }
        }

        return (string) (getcwd() ?: '.');
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
