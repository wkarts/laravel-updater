<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SoftMaintenanceMiddleware
{
    /**
     * Chave única para ativar/desativar manutenção "soft".
     *
     * Observação:
     * - Intencionalmente não depende de artisan down/up, porque isso tende a bloquear o próprio updater
     *   e (em versões recentes) o "--except" é inconsistente.
     * - A manutenção soft preserva o acesso ao prefixo do updater (UPDATER_UI_PREFIX).
     */
    public const CACHE_KEY = 'argws_laravel_updater_soft_maintenance';

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) Cache::get(self::CACHE_KEY, false);
        if (!$enabled) {
            return $next($request);
        }

        $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');

        // Sempre libera o próprio updater
        if ($prefix !== '' && ($request->is($prefix) || $request->is($prefix.'/*'))) {
            return $next($request);
        }

        // Permite também healthchecks (opcional) para evitar falsos negativos
        $healthAllow = (array) config('updater.maintenance.soft_allow', ['/updater/health', '/health', '/healthz']);
        foreach ($healthAllow as $path) {
            $path = trim((string) $path);
            if ($path !== '' && $request->is(ltrim($path, '/'))) {
                return $next($request);
            }
        }

        // Resposta 503 usando a view do pacote (whitelabel)
        return response()->view('laravel-updater::maintenance', [], 503);
    }
}
