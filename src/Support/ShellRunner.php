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

        $workingDirectory = $this->resolveWorkingDirectory($cwd, $command);

        // Em ambientes não-interativos (Supervisor, cron, PHP-FPM), o PATH pode vir reduzido.
        // Isso causa exit code 127 (command not found) mesmo com o binário instalado.
        // Também fazemos merge com o ambiente já existente do processo para não perder variáveis do host.
        $env = $this->normalizeEnv($env, $workingDirectory ?: '.');

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptor, $pipes, $workingDirectory ?: '.', $env);

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


    /** @param array<int,string> $command */
    

    /**
     * Executa um comando com timeout (em segundos).
     *
     * Mantém compatibilidade: não altera o comportamento do run() existente.
     *
     * @return array{code:int,stdout:string,stderr:string,cmd:string}
     */
    public function runWithTimeout(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): array
    {
        $cmd = $this->normalizeCommand($command);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $cmd,
            $descriptorspec,
            $pipes,
            $cwd ?? base_path(),
            $this->buildEnv($env)
        );

        if (!is_resource($process)) {
            return [
                'code' => 1,
                'stdout' => '',
                'stderr' => 'Falha ao iniciar processo',
                'cmd' => $cmd,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], False);
        stream_set_blocking($pipes[2], False);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) >= $timeoutSeconds) {
                @proc_terminate($process);
                usleep(250000);
                $statusAfter = proc_get_status($process);
                if ($statusAfter['running']) {
                    @proc_terminate($process, 9);
                }

                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new UpdaterException(sprintf('Comando excedeu timeout (%ss): %s', $timeoutSeconds, $cmd));
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'code' => (int) $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'cmd' => $cmd,
        ];
    }

    public function runOrFailWithTimeout(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): array
    {
        $res = $this->runWithTimeout($command, $cwd, $env, $timeoutSeconds);
        if (($res['code'] ?? 1) !== 0) {
            throw new UpdaterException($res['stderr'] ?: 'Comando falhou', $res);
        }
        return $res;
    }


    /**
     * Normaliza um comando (array) para string segura para uso com proc_open(string).
     *
     * Observação: o run() já aceita array diretamente, mas o runWithTimeout()
     * utiliza proc_open(string) para permitir controle manual do loop/timeout.
     *
     * @param array<int,string> $command
     */
    private function normalizeCommand(array $command): string
    {
        if ($command === []) {
            throw new UpdaterException('Comando inválido: vazio.');
        }

        // Remove itens vazios e normaliza tipo
        $parts = [];
        foreach ($command as $part) {
            $p = trim((string) $part);
            if ($p === '') {
                continue;
            }
            $parts[] = $p;
        }

        if ($parts === []) {
            throw new UpdaterException('Comando inválido: vazio.');
        }

        // Em Linux/macOS, proc_open(string) invoca o shell.
        // Então escapamos cada argumento para evitar quebra por espaços/caracteres especiais.
        if (DIRECTORY_SEPARATOR !== '\\') {
            return implode(' ', array_map('escapeshellarg', $parts));
        }

        // Windows: mantém a compatibilidade; ainda escapamos com aspas quando necessário.
        $escaped = [];
        foreach ($parts as $p) {
            if (preg_match('/\s|["]/', $p)) {
                $p = '"' . str_replace('"', '\"', $p) . '"';
            }
            $escaped[] = $p;
        }

        return implode(' ', $escaped);
    }

    /**
     * Monta o ambiente para execução de processos.
     *
     * @param array<string,string> $env
     * @return array<string,string>
     */
    private function buildEnv(array $env = []): array
    {
        // Reusa a lógica que:
        // - herda variáveis do processo (PATH etc.)
        // - complementa chaves a partir do .env (quando necessário)
        // - garante diretórios padrão para execuções não-interativas
        return $this->normalizeEnv($env, base_path());
    }

private function resolveWorkingDirectory(?string $cwd, array $command): string
    {
        if (is_string($cwd) && trim($cwd) !== '') {
            return $cwd;
        }

        $configured = '';
        try {
            if (function_exists('config')) {
                $configured = (string) config('updater.git.path', '');
            }
        } catch (\Throwable $e) {
            $configured = '';
        }

        if ($configured !== '' && is_dir($configured)) {
            if (is_file(rtrim($configured, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artisan')) {
                return $configured;
            }

            return $configured;
        }

        $base = '';
        try {
            if (function_exists('base_path')) {
                $base = (string) base_path();
            }
        } catch (\Throwable $e) {
            $base = '';
        }
        if ($base !== '' && is_dir($base)) {
            if (is_file(rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artisan')) {
                return $base;
            }

            return $base;
        }

        // Fallback robusto para processos FPM/CLI com cwd inconsistente.
        $argv0 = strtolower(trim((string) ($command[0] ?? '')));
        $argv1 = strtolower(trim((string) ($command[1] ?? '')));
        if (in_array($argv0, ['php', 'php.exe'], true) && $argv1 === 'artisan') {
            $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
            if ($script !== '') {
                $dir = dirname($script);
                if (is_file(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artisan')) {
                    return $dir;
                }
            }
        }

        return (string) (getcwd() ?: '.');
    }

    /** @param array<string, string> $env */
    private function normalizeEnv(array $env, ?string $cwd = null): array
    {
        $inherited = $this->inheritedEnvironment();
        $final = array_merge($inherited, $env);

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

        // Composer exige HOME ou COMPOSER_HOME. Em ambientes não-interativos (cron/supervisor/PHP-FPM)
        // isso pode vir vazio e o comando falha com: "The HOME or COMPOSER_HOME environment variable must be set".
        $home = $final['HOME'] ?? (getenv('HOME') ?: ($_SERVER['HOME'] ?? null));

        if ($home === null || trim((string) $home) === '') {
            // tenta descobrir pelo usuário efetivo do SO
            if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
                $pw = @posix_getpwuid(@posix_geteuid());
                if (is_array($pw) && !empty($pw['dir'])) {
                    $home = (string) $pw['dir'];
                }
            }
        }

        if ($home === null || trim((string) $home) === '') {
            // fallback seguro quando não existe home (ex.: usuário sem shell)
            $home = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'laravel-updater-home';
        }

        if (!is_dir($home)) {
            @mkdir($home, 0775, true);
        }

        $final['HOME'] = $final['HOME'] ?? $home;

        $composerHome = $final['COMPOSER_HOME'] ?? (getenv('COMPOSER_HOME') ?: null);
        if ($composerHome === null || trim((string) $composerHome) === '') {
            $composerHome = rtrim((string) $final['HOME'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.composer';
        }
        if (!is_dir($composerHome)) {
            @mkdir($composerHome, 0775, true);
        }
        $final['COMPOSER_HOME'] = $final['COMPOSER_HOME'] ?? $composerHome;

        $final['COMPOSER_CACHE_DIR'] = $final['COMPOSER_CACHE_DIR'] ?? (rtrim((string) $composerHome, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cache');
        if (!is_dir($final['COMPOSER_CACHE_DIR'])) {
            @mkdir($final['COMPOSER_CACHE_DIR'], 0775, true);
        }

        // Fallback para chaves lidas via getenv()/env() em providers/helpers do host.
        // Evita falha de comandos de cache quando o host usa ENCRYPTION_KEY fora de config.
        $this->hydrateKeyFromDotEnv($final, 'ENCRYPTION_KEY', $cwd);
        $this->hydrateKeyFromDotEnv($final, 'APP_KEY', $cwd);

        $common[] = rtrim((string) $final['HOME'], '/') . '/bin';
        $common[] = rtrim((string) $final['COMPOSER_HOME'], '/') . '/vendor/bin';

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
    private function hydrateKeyFromDotEnv(array &$env, string $key, ?string $cwd = null): void
    {
        if (($env[$key] ?? '') !== '') {
            return;
        }

        $root = $cwd ?: (getcwd() ?: '.');
        $dotEnvPath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($dotEnvPath)) {
            return;
        }

        $contents = @file_get_contents($dotEnvPath);
        if (!is_string($contents) || $contents === '') {
            return;
        }

        $pattern = '/^' . preg_quote($key, '/') . '=([^\r\n]*)/m';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return;
        }

        $value = trim((string) $matches[1]);
        $value = trim($value, "\"'");
        if ($value !== '') {
            $env[$key] = $value;
        }
    }

    /** @return array<string,string> */
    private function inheritedEnvironment(): array
    {
        $base = [];
        $all = getenv();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (is_string($v) || is_numeric($v)) {
                    $base[$k] = (string) $v;
                }
            }
        }

        foreach ([$_ENV ?? [], $_SERVER ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (is_string($v) || is_numeric($v)) {
                    $base[$k] = (string) $v;
                }
            }
        }

        return $base;
    }

    /** @param array<string, string> $env */
    public function runOrFail(array $command, ?string $cwd = null, array $env = []): array
    {
        // Evita "run eterno": alguns comandos podem ficar aguardando input (git auth)
        // ou travar por tempo indeterminado em produção.
        $timeout = $this->guessTimeoutSeconds($command);

        if ($timeout !== null && $timeout > 0) {
            $result = $this->runOrFailWithTimeout($command, $cwd, $env, $timeout);
        } else {
            $result = $this->run($command, $cwd, $env);
        }
        if ($result['exit_code'] !== 0) {
            $cmdStr = implode(' ', array_map(static fn ($p) => (string) $p, $command));
            $msg = trim((string) ($result['stderr'] ?: $result['stdout']));
            $suffix = $msg !== '' ? (' ' . $msg) : '';
            throw new UpdaterException(sprintf('Comando falhou (%s): %s%s', $result['exit_code'], $cmdStr, $suffix));
        }

        return $result;
    }

    /**
     * Timeout por tipo de comando, configurável por .env.
     *
     * UPDATER_TIMEOUT_GIT (default 300)
     * UPDATER_TIMEOUT_COMPOSER (default 1800)
     * UPDATER_TIMEOUT_ARTISAN (default 1800)
     * UPDATER_TIMEOUT_DEFAULT (default 0 = sem timeout)
     */
    private function guessTimeoutSeconds(array $command): ?int
    {
        $bin = strtolower((string) ($command[0] ?? ''));
        $default = (int) env('UPDATER_TIMEOUT_DEFAULT', 0);

        if ($bin === 'git') {
            return (int) env('UPDATER_TIMEOUT_GIT', 300);
        }

        if (str_contains($bin, 'composer')) {
            return (int) env('UPDATER_TIMEOUT_COMPOSER', 1800);
        }

        if ($bin === 'php' && isset($command[1]) && (string) $command[1] === 'artisan') {
            return (int) env('UPDATER_TIMEOUT_ARTISAN', 1800);
        }

        return $default > 0 ? $default : null;
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
