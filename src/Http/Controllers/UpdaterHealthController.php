<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness do próprio updater: acessível durante a manutenção, sem expor detalhes.
 * Não executa comandos de atualização, não altera locks ou credenciais.
 */
final class UpdaterHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if ((bool) config('updater.healthcheck.check_database', false)) {
            try {
                DB::connection()->select('SELECT 1');
            } catch (Throwable) {
                return $this->response(false);
            }
        }

        return $this->response(true);
    }

    private function response(bool $healthy): JsonResponse
    {
        return new JsonResponse(
            [
                'updater_health' => true,
                'status' => $healthy ? 'ok' : 'unhealthy',
            ],
            $healthy ? 200 : 503,
            [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex',
            ]
        );
    }
}
