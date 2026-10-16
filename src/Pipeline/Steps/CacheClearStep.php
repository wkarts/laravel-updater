<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Illuminate\Support\Facades\Cache;
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
            $softMaintenance = $this->softMaintenanceState();
            try {
                $startedNs = hrtime(true);
                $result = $this->shellRunner->runOrFail(['php', 'artisan', $command]);
                $context['cache_clear_commands'][] = [
                    'command' => 'php artisan ' . $command,
                    'exit_code' => (int) ($result['exit_code'] ?? 0),
                    'duration_ms' => (int) round((hrtime(true) - $startedNs) / 1000000),
                ];
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
            } finally {
                $this->restoreSoftMaintenance($softMaintenance);
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
        $softMaintenance = $this->softMaintenanceState();
        try {
            $this->shellRunner->run(['php', 'artisan', 'optimize:clear']);
        } finally {
            $this->restoreSoftMaintenance($softMaintenance);
        }
    }

    private function restoreSoftMaintenance(mixed $previous): void
    {
        // optimize:clear limpa também o cache da aplicação. Restaure somente
        // a flag existente, preservando uma alteração mais recente se presente.
        if ($previous && !$this->softMaintenanceState()) {
            Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, $previous);
        }
    }

    private function softMaintenanceState(): mixed
    {
        try {
            return Cache::get(SoftMaintenanceMiddleware::CACHE_KEY, false);
        } catch (\Throwable) {
            // Mantém o uso isolado da etapa sem um container Laravel inicializado.
            return false;
        }
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
