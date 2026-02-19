<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Middleware;

use Argws\LaravelUpdater\Support\StateStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft maintenance mode controlled by the updater.
 *
 * Por que existe:
 * - O maintenance nativo do Laravel (artisan down) bloqueia TUDO, incluindo a UI do updater.
 * - Aqui bloqueamos o app inteiro, mas mantemos SEMPRE o prefixo do updater liberado.
 */
final class SoftMaintenanceMiddleware
{
    public function __construct(
        private readonly StateStore $stateStore,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningInConsole()) {
            return $next($request);
        }

        $state = $this->stateStore->get('soft_maintenance', ['enabled' => false]);
        $enabled = (bool) ($state['enabled'] ?? false);

        if (!$enabled) {
            return $next($request);
        }

        $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
        if ($prefix !== '' && ($request->is($prefix) || $request->is($prefix . '/*'))) {
            return $next($request);
        }

        $message = (string) ($state['message'] ?? 'Estamos atualizando o sistema. Volte em alguns minutos.');
        $title = (string) ($state['title'] ?? 'Atualização em andamento');

        $view = (string) ($state['view'] ?? 'laravel-updater::maintenance');
        if (trim($view) === '') {
            $view = 'laravel-updater::maintenance';
        }

        return response()->view($view, [
            'title' => $title,
            'message' => $message,
        ], 503);
    }
}
