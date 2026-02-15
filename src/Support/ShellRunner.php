<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Exceptions\UpdaterException;

class ShellRunner
{
    /**
     * Resolve um binário a partir de uma lista de candidatos.
     * Retorna o primeiro candidato executável/encontrado no PATH.
     *
     * @param array<int, string> $candidates
     */
    public function resolveBinary(array $candidates): ?string
    {
        foreach ($candidates as $bin) {
            $bin = trim((string) $bin);
            if ($bin === '') {
                continue;
            }
            // Caminho absoluto
            if (str_starts_with($bin, '/') && is_file($bin) && is_executable($bin)) {
                return $bin;
            }
            // Procura no PATH
            if ($this->binaryExists($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /** @param array<string, string> $env */
    public function run(array $command, ?string $cwd = null, array $env = []): array
    {
        if ($command === []) {
            throw new UpdaterException('Comando inválido: vazio.');
        }

        // Em ambientes não-interativos (Supervisor, cron, PHP-FPM), o PATH pode vir reduzido.
        // Isso causa exit code 127 (command not found) mesmo com o binário instalado.
        // Aqui fazemos um fallback seguro, preservando o PATH original e garantindo diretórios comuns.
        $env = $this->normalizeEnv($env);

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $workingDirectory = $cwd ?? getcwd();

        $process = @proc_open($command, $descriptor, $pipes, $workingDirectory ?: '.', $env === [] ? null : $env);

        if (!is_resource($process)) {
            // Quando o executável não existe (ex.: composer não está no PATH), o proc_open pode falhar.
            throw new UpdaterException('Falha ao iniciar comando de sistema (binário ausente ou sem permissão).');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'command' => implode(' ', $command),
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'exit_code' => $exitCode,
        ];
    }

    /** @param array<string, string> $env */
    private function normalizeEnv(array $env): array
    {
        $final = $env;

        $path = $final['PATH'] ?? (getenv('PATH') ?: '');

        $common = [
            '/usr/local/sbin',
            '/usr/local/bin',
            '/usr/sbin',
            '/usr/bin',
            '/sbin',
            '/bin',
            '/snap/bin',
        ];

        $home = getenv('HOME') ?: null;
        if ($home) {
            $common[] = rtrim($home, '/').'/bin';
            $common[] = rtrim($home, '/').'/.composer/vendor/bin';
        }

        $parts = array_values(array_filter(explode(':', (string) $path), static fn ($p) => trim((string) $p) !== ''));

        foreach (array_reverse($common) as $dir) {
            if (!in_array($dir, $parts, true)) {
                array_unshift($parts, $dir);
            }
        }

        $final['PATH'] = implode(':', $parts);

        return $final;
    }

    /** @param array<string, string> $env */
    public function runOrFail(array $command, ?string $cwd = null, array $env = []): array
    {
        $result = $this->run($command, $cwd, $env);
        if ($result['exit_code'] !== 0) {
            $cmdStr = implode(' ', array_map(static fn ($p) => (string) $p, $command));
            $msg = trim((string) ($result['stderr'] ?: $result['stdout']));
            $suffix = $msg !== '' ? (' ' . $msg) : '';
            throw new UpdaterException(sprintf('Comando falhou (%s): %s%s', $result['exit_code'], $cmdStr, $suffix));
        }

        return $result;
    }

    public function binaryExists(string $binary): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $result = $this->run(['where', $binary]);

            return $result['exit_code'] === 0;
        }

        $result = $this->run(['which', $binary]);

        return $result['exit_code'] === 0;
    }
}
