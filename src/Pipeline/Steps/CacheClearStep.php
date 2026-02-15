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
        foreach (['optimize:clear', 'config:cache', 'route:cache', 'view:cache'] as $command) {
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
