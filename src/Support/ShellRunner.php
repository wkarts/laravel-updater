<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Exceptions\UpdaterException;

class ShellRunner
{
    public function run(array $command, ?string $cwd = null): array
    {
        if ($command === []) {
            throw new UpdaterException('Comando inválido: vazio.');
        }

        $escaped = array_map(static fn (string $part): string => escapeshellarg($part), $command);
        $fullCommand = implode(' ', $escaped);

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $workingDirectory = $cwd ?? getcwd();
        $process = proc_open($fullCommand, $descriptor, $pipes, $workingDirectory ?: '.');

        if (!is_resource($process)) {
            throw new UpdaterException('Falha ao iniciar comando de sistema.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'command' => $fullCommand,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'exit_code' => $exitCode,
        ];
    }

    public function runOrFail(array $command, ?string $cwd = null): array
    {
        $result = $this->run($command, $cwd);
        if ($result['exit_code'] !== 0) {
            throw new UpdaterException(sprintf('Comando falhou (%s): %s', $result['exit_code'], $result['stderr'] ?: $result['stdout']));
        }

        return $result;
    }
}
