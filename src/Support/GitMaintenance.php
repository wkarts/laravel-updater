<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Exceptions\GitException;

/**
 * Mantém o repositório git "leve" e saudável em produção.
 *
 * Objetivos:
 * - Evitar crescimento indefinido do .git por packs/refs/reflog acumulados.
 * - Executar manutenção automática pré/pós update e também via comando/scheduler.
 * - Ser seguro: nunca altera o working tree fora de operações git de manutenção.
 *
 * Observação importante:
 * - Isso NÃO substitui uma política de deploy via artefatos; apenas torna o modo "git inplace" mais estável.
 */
class GitMaintenance
{
    public function __construct(
        private readonly ShellRunner $shell,
        private readonly array $config
    ) {
    }

    public function sizeBytes(): int
    {
        $gitDir = $this->gitDir();
        if (!is_dir($gitDir)) {
            return 0;
        }

        // Tenta du primeiro (mais rápido em Linux)
        $res = $this->shell->run(['bash', '-lc', 'du -sb .git 2>/dev/null | cut -f1'], $this->cwd());
        if (($res['exit_code'] ?? 1) === 0) {
            $val = trim((string) ($res['stdout'] ?? ''));
            if ($val !== '' && ctype_digit($val)) {
                return (int) $val;
            }
        }

        // Fallback em PHP (pode ser mais lento, mas garante portabilidade)
        return $this->dirSizeBytes($gitDir);
    }

    /**
     * Executa manutenção baseada em thresholds.
     * Retorna relatório para logs/UI.
     */
    public function maintain(string $reason = 'manual'): array
    {
        $cfg = $this->cfg();

        $enabled = (bool) ($cfg['enabled'] ?? true);
        if (!$enabled) {
            return [
                'enabled' => false,
                'reason' => $reason,
                'skipped' => true,
                'message' => 'Git maintenance desativado.',
                'before_bytes' => $this->sizeBytes(),
                'after_bytes' => $this->sizeBytes(),
                'actions' => [],
            ];
        }

        if (!$this->isGitRepository()) {
            return [
                'enabled' => true,
                'reason' => $reason,
                'skipped' => true,
                'message' => 'Diretório não é repositório git; manutenção ignorada.',
                'before_bytes' => 0,
                'after_bytes' => 0,
                'actions' => [],
            ];
        }

        $actions = [];
        $before = $this->sizeBytes();

        // Camada 1: higiene rápida sempre
        // Se não existe remote origin (ex.: bootstrap parcial/ambiente sem remoto), ignora ações que dependem do remoto.
        $hasOrigin = $this->hasRemoteOrigin();
        if ($hasOrigin) {
            $this->runOk(['git', 'remote', 'prune', 'origin'], $actions, 'remote_prune');
            $this->runOk(['git', 'fetch', '--prune', '--prune-tags', 'origin'], $actions, 'fetch_prune');
        } else {
            $actions[] = [
                'action' => 'remote_prune',
                'cmd' => 'git remote prune origin',
                'ok' => false,
                'stderr' => "skip: remote 'origin' não configurado",
            ];
            $actions[] = [
                'action' => 'fetch_prune',
                'cmd' => 'git fetch --prune --prune-tags origin',
                'ok' => false,
                'stderr' => "skip: remote 'origin' não configurado",
            ];
        }
        $this->runOk(['git', 'gc', '--prune=now'], $actions, 'gc_prune_now');

        $afterQuick = $this->sizeBytes();

        $aggressiveThresholdMb = max(0, (int) ($cfg['aggressive_threshold_mb'] ?? 512));
        $maxSizeMb = max(0, (int) ($cfg['max_size_mb'] ?? 1024));
        $depth = max(1, (int) ($cfg['shallow_depth'] ?? 50));

        if ($aggressiveThresholdMb > 0 && $this->bytesToMb($afterQuick) >= $aggressiveThresholdMb) {
            // Camada 2: limpeza pesada
            $this->runOk(['git', 'reflog', 'expire', '--expire=now', '--all'], $actions, 'reflog_expire');
            $this->runOk(['git', 'gc', '--prune=now', '--aggressive'], $actions, 'gc_aggressive');
        }

        $afterHeavy = $this->sizeBytes();

        // Camada 2b: guardrail de tamanho
        if ($maxSizeMb > 0 && $this->bytesToMb($afterHeavy) >= $maxSizeMb) {
            $lightMode = (bool) ($cfg['light_mode_enabled'] ?? true);
            if ($lightMode) {
                // Converte para shallow (histórico reduzido) sem re-clone do app inteiro
                // Atenção: tags muito antigas podem exigir deepen manual no futuro.
                $branch = (string) ($this->config['git']['branch'] ?? 'main');
                $this->runOk(['git', 'fetch', '--depth=' . $depth, '--prune', '--prune-tags', 'origin', $branch], $actions, 'fetch_shallow_branch');
                // Tags: opcional
                $includeTags = (bool) ($cfg['light_mode_fetch_tags'] ?? true);
                if ($includeTags) {
                    $this->runOk(['git', 'fetch', '--depth=' . $depth, '--tags', '--prune', 'origin'], $actions, 'fetch_shallow_tags');
                }
                $this->runOk(['git', 'gc', '--prune=now'], $actions, 'gc_after_shallow');
            } else {
                $actions[] = [
                    'action' => 'light_mode_disabled',
                    'ok' => false,
                    'message' => 'Tamanho excedeu o limite, mas light mode está desativado.',
                ];
            }
        }

        $after = $this->sizeBytes();

        return [
            'enabled' => true,
            'reason' => $reason,
            'skipped' => false,
            'before_bytes' => $before,
            'after_bytes' => $after,
            'before_mb' => $this->bytesToMb($before),
            'after_mb' => $this->bytesToMb($after),
            'actions' => $actions,
        ];
    }

    private function cfg(): array
    {
        return (array) ($this->config['git_maintenance'] ?? []);
    }

    private function cwd(): string
    {
        $path = (string) (($this->config['git']['path'] ?? '') ?: base_path());
        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function gitDir(): string
    {
        return $this->cwd() . DIRECTORY_SEPARATOR . '.git';
    }

    
    private function hasRemoteOrigin(): bool
    {
        // git remote get-url origin (exit 0) indica origin configurado
        $res = $this->shell->run(['git', 'remote', 'get-url', 'origin'], $this->cwd());
        return is_array($res) && (int) ($res['exit_code'] ?? 1) === 0 && trim((string) ($res['stdout'] ?? '')) !== '';
    }

private function isGitRepository(): bool
    {
        return is_dir($this->gitDir());
    }

    private function runOk(array $cmd, array &$actions, string $name): void
    {
        $res = $this->shell->run($cmd, $this->cwd());
        $ok = (($res['exit_code'] ?? 1) === 0);
        $actions[] = [
            'action' => $name,
            'cmd' => implode(' ', $cmd),
            'ok' => $ok,
            'stderr' => $ok ? null : (string) ($res['stderr'] ?? ''),
        ];

        if (!$ok) {
            // Não aborta o update por falha de manutenção; só registra.
            // A manutenção é melhor-esforço.
        }
    }

    private function dirSizeBytes(string $dir): int
    {
        $size = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            try {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            } catch (\Throwable) {
                // ignora
            }
        }

        return $size;
    }

    private function bytesToMb(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }

        return (int) ceil($bytes / (1024 * 1024));
    }
}
