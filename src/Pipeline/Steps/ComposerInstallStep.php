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
        $composerPath = function_exists('base_path') ? base_path('composer.json') : 'composer.json';
        if (is_file($composerPath)) {
            $json = json_decode((string) file_get_contents($composerPath), true);
            $require = is_array($json['require'] ?? null) ? $json['require'] : [];
            $requireDev = is_array($json['require-dev'] ?? null) ? $json['require-dev'] : [];
            $present = array_key_exists(self::REQUIRED_PACKAGE, $require) || array_key_exists(self::REQUIRED_PACKAGE, $requireDev);
            if (!$present) {
                $this->shellRunner->runOrFail(['composer', 'require', self::REQUIRED_PACKAGE, '--no-interaction', '--no-update']);
            }
        }

        $this->shellRunner->runOrFail(['composer', 'install', '--no-interaction', '--prefer-dist', '--optimize-autoloader']);
    }

    public function rollback(array &$context): void
    {
        $this->shellRunner->run(['composer', 'install', '--no-interaction']);
    }
}
