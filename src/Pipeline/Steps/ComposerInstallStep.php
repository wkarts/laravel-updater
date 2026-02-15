<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\ShellRunner;

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

        $configured = (string) config('updater.composer.bin', env('UPDATER_COMPOSER_BIN', 'composer'));
        $candidates = array_values(array_filter([
            $configured,
            'composer',
            'composer2',
            $projectPath . '/composer.phar',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ], static fn ($v) => is_string($v) && trim($v) !== ''));

        $composerBin = $this->shellRunner->resolveBinary($candidates);
        if ($composerBin === null) {
            throw new \RuntimeException('Composer não encontrado. Defina UPDATER_COMPOSER_BIN (ex.: /usr/bin/composer ou /caminho/composer.phar) ou instale o composer no PATH.');
        }

        $composerCmd = str_ends_with($composerBin, '.phar')
            ? [PHP_BINARY, $composerBin]
            : [$composerBin];

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

        $this->shellRunner->runOrFail([...$composerCmd, 'install', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], $projectPath);
    }

    public function rollback(array &$context): void
    {
        $projectPath = function_exists('base_path') ? base_path() : getcwd();
        $configured = (string) config('updater.composer.bin', env('UPDATER_COMPOSER_BIN', 'composer'));
        $composerBin = $this->shellRunner->resolveBinary([$configured, 'composer', 'composer2', $projectPath . '/composer.phar', '/usr/local/bin/composer', '/usr/bin/composer']);
        if ($composerBin === null) {
            return;
        }
        $composerCmd = str_ends_with($composerBin, '.phar') ? [PHP_BINARY, $composerBin] : [$composerBin];
        $this->shellRunner->run([...$composerCmd, 'install', '--no-interaction'], $projectPath);
    }
}
