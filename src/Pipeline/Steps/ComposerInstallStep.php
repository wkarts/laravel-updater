<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Support\PipelineContext;

final class ComposerInstallStep implements StepInterface
{
    public function name(): string
    {
        return 'composer_install';
    }

    public function run(PipelineContext $context): void
    {
        $cmd = $this->composerCommand();

        // composer install --no-interaction --prefer-dist
        $cmd = array_merge($cmd, ['install', '--no-interaction', '--prefer-dist']);

        $context->shell()->runOrFail($cmd, $context->projectPath());
    }

    public function rollback(PipelineContext $context): void
    {
        // Sem rollback específico
    }

    /**
     * Resolve o comando do Composer de forma compatível:
     * - UPDATER_COMPOSER_BIN=composer (padrão)
     * - UPDATER_COMPOSER_BIN=/usr/bin/composer
     * - UPDATER_COMPOSER_BIN=/caminho/composer.phar (usa UPDATER_PHP_BIN/php)
     * - fallback automático: se "composer" não existir, tenta {project}/composer.phar
     */
    private function composerCommand(): array
    {
        $composer = (string) config('updater.commands.composer', 'composer');
        $php      = (string) config('updater.commands.php', 'php');

        $composer = trim($composer);

        // Se informaram um .phar diretamente
        if ($composer !== '' && str_ends_with($composer, '.phar')) {
            return [$php, $composer];
        }

        // Se for um caminho absoluto e existir
        if ($composer !== '' && str_contains($composer, '/') && is_file($composer)) {
            // pode ser binário ou phar
            if (str_ends_with($composer, '.phar')) {
                return [$php, $composer];
            }

            return [$composer];
        }

        // Tenta "composer" padrão
        return [$composer !== '' ? $composer : 'composer'];
    }
}
