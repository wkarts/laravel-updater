<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Exceptions\UpdaterException;
use Argws\LaravelUpdater\Support\ShellRunner;

class CacheClearStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner)
    {
    }

    public function name(): string { return 'cache_clear'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        foreach ($this->commandsToRun($context) as $command) {
            try {
                $this->shellRunner->runOrFail(['php', 'artisan', $command]);
            } catch (UpdaterException $exception) {
                if ($command === 'route:cache' && $this->isRouteCacheDuplicateNameError($exception)) {
                    if ($this->ignoreDuplicateRouteCacheFailure()) {
                        $context['cache_clear_warning'][] = [
                            'command' => $command,
                            'reason' => 'duplicate_route_name',
                            'action' => 'route:clear',
                            'error' => $exception->getMessage(),
                        ];
                        $this->shellRunner->run(['php', 'artisan', 'route:clear']);
                        continue;
                    }
                }

                throw $exception;
            }
        }
    }

    /**
 * @return array<int,string>
 */
private function commandsToRun(array $context): array
{
    // Por padrão, NÃO executamos config:cache para evitar quebrar aplicações que usam env() em runtime
    // (ex.: helpers/providers que exigem ENCRYPTION_KEY via env). Em Laravel, quando config é cacheado,
    // o bootstrap não carrega .env, então env() passa a retornar vazio se a variável não existir no ambiente do SO.
    // Você pode reativar config:cache explicitamente via:
    // - config: updater.cache.config_cache = true
    // - env: UPDATER_CACHE_CONFIG_CACHE=true
    $runConfigCache = $this->shouldRunConfigCache();

    $commands = ['optimize:clear'];

    if ($runConfigCache) {
        $commands[] = 'config:cache';
    } else {
        $commands[] = 'config:clear';
    }

    $commands[] = 'route:cache';
    $commands[] = 'view:cache';

    return $commands;
}

private function shouldRunConfigCache(): bool
{
    try {
        if (function_exists('config')) {
            return (bool) config('updater.cache.config_cache', false);
        }
    } catch (\Throwable) {
        // fallback abaixo
    }

    $env = getenv('UPDATER_CACHE_CONFIG_CACHE');
    if ($env === false || $env === null || $env === '') {
        return false;
    }

    return filter_var($env, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
}

public function rollback(array &$context): void
    {
        $this->shellRunner->run(['php', 'artisan', 'optimize:clear']);
    }

    private function ignoreDuplicateRouteCacheFailure(): bool
    {
        // Em testes unitários puros (sem container Laravel), o helper config() existe,
        // mas pode lançar BindingResolutionException ao resolver o serviço "config".
        // Neste cenário, usamos fallback por ENV e default true.
        try {
            if (function_exists('config')) {
                return (bool) config('updater.cache.ignore_route_cache_duplicate_name', true);
            }
        } catch (\Throwable) {
            // fallback abaixo
        }

        $env = getenv('UPDATER_CACHE_IGNORE_ROUTE_CACHE_DUPLICATE_NAME');
        if ($env === false || $env === null || $env === '') {
            return true;
        }

        return filter_var($env, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function isRouteCacheDuplicateNameError(UpdaterException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'unable to prepare route')
            && str_contains($message, 'another route has already been assigned name');
    }
}
