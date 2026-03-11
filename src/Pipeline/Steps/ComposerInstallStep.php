<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;
use Throwable;

class ComposerInstallStep implements PipelineStepInterface
{
    private const REQUIRED_PACKAGE = 'argws/laravel-updater';

    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'composer_install'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        $projectPath = function_exists('base_path') ? base_path() : getcwd();
        $composerCmd = $this->resolveComposerCommand($projectPath, $context);

        // Sem composer disponível: só permite seguir se vendor já está íntegro.
        if ($composerCmd === null) {
            if ($this->hasHealthyVendor($projectPath)) {
                $context['composer_install_warning'] = 'Composer indisponível no ambiente; etapa composer_install ignorada com vendor já presente.';
                return;
            }

            throw new \RuntimeException('Composer não encontrado e vendor/autoload.php ausente. Defina UPDATER_COMPOSER_BIN (ex.: /usr/bin/composer ou /caminho/composer.phar) ou instale o composer no PATH.');
        }

        $composerPath = function_exists('base_path') ? base_path('composer.json') : 'composer.json';
        if (is_file($composerPath)) {
            $json = json_decode((string) file_get_contents($composerPath), true);
            $require = is_array($json['require'] ?? null) ? $json['require'] : [];
            $requireDev = is_array($json['require-dev'] ?? null) ? $json['require-dev'] : [];
            $present = array_key_exists(self::REQUIRED_PACKAGE, $require) || array_key_exists(self::REQUIRED_PACKAGE, $requireDev);
            if (!$present) {
                $this->shellRunner->runOrFail([...$composerCmd, 'require', self::REQUIRED_PACKAGE, '--no-interaction', '--no-update'], $projectPath);
            }
        }

        try {
            $this->shellRunner->runOrFail([...$composerCmd, 'install', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], $projectPath);
        } catch (Throwable $e) {
            // Ambiente sem composer no PATH/binário inválido: segue com vendor já existente.
            if ($this->isComposerUnavailableError($e) && $this->hasHealthyVendor($projectPath)) {
                $context['composer_install_warning'] = 'Falha ao executar composer install (binário indisponível no runtime); mantendo vendor existente.';
                return;
            }

            throw $e;
        }
    }

    public function rollback(array &$context): void
    {
        $projectPath = function_exists('base_path') ? base_path() : getcwd();
        $composerCmd = $this->resolveComposerCommand($projectPath, $context);
        if ($composerCmd === null) {
            return;
        }

        $this->shellRunner->run([...$composerCmd, 'install', '--no-interaction'], $projectPath);
    }

    /** @return array<int,string>|null */
    private function resolveComposerCommand(string $projectPath, array &$context): ?array
    {
        $configured = trim((string) config('updater.composer.bin', env('UPDATER_COMPOSER_BIN', 'composer')));

        $rawCandidates = array_values(array_filter([
            $configured,
            'composer',
            'composer2',
            $projectPath . '/composer.phar',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            dirname(PHP_BINARY) . '/composer',
        ], static fn ($v) => is_string($v) && trim($v) !== ''));

        foreach ($rawCandidates as $candidate) {
            $candidate = trim((string) $candidate);
            $cmd = str_ends_with($candidate, '.phar') ? [PHP_BINARY, $candidate] : [$candidate];

            try {
                $result = $this->shellRunner->run([...$cmd, '--version'], $projectPath);
                if ((int) ($result['exit_code'] ?? 1) === 0) {
                    return $cmd;
                }
            } catch (Throwable $e) {
                // tenta próximo candidato
            }
        }

        $context['composer_install_warning'] = 'Nenhum binário composer funcional encontrado no runtime.';

        return null;
    }

    private function hasHealthyVendor(string $projectPath): bool
    {
        $autoload = rtrim($projectPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        return is_file($autoload);
    }

    private function isComposerUnavailableError(Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'falha ao iniciar processo')
            || str_contains($message, 'binário ausente')
            || str_contains($message, 'command not found')
            || str_contains($message, 'exit=127')
            || str_contains($message, "cmd='composer'");
    }
}
