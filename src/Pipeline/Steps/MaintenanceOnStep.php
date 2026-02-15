<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Pipeline\UpdateContext;
use Argws\LaravelUpdater\Support\ShellRunner;
use Throwable;

/**
 * Coloca a aplicação em modo manutenção.
 *
 * Observação:
 * - Em alguns ambientes CLI (ou configurações específicas), `php artisan down --render=...`
 *   pode falhar com erro do tipo "Undefined array key REQUEST_URI".
 * - Para garantir robustez, este step tenta com --render e, se falhar, faz fallback para `down` simples.
 */
class MaintenanceOnStep implements StepInterface
{
    public function __construct(private readonly ShellRunner $shell)
    {
    }

    public function name(): string
    {
        return 'maintenance_on';
    }

    public function run(UpdateContext $ctx): void
    {
        $useRender = (bool) config('updater.maintenance.use_render', true);
        $renderView = (string) config('updater.maintenance.render_view', 'errors::503');
        $retry = (int) config('updater.maintenance.retry', 60);
        $fallback = (bool) config('updater.maintenance.fallback_no_render', true);

        // 1) Tenta `down --render=...` se habilitado
        if ($useRender && $renderView !== '') {
            try {
                $this->shell->run([
                    'php', 'artisan', 'down',
                    '--render=' . $renderView,
                    '--retry=' . (string) $retry,
                ], $ctx);

                return;
            } catch (Throwable $e) {
                $msg = $e->getMessage();

                // Loga e tenta fallback
                if ($fallback) {
                    $ctx->logger->warning('Falhou ao entrar em modo manutenção com --render. Tentando fallback sem --render.', [
                        'render_view' => $renderView,
                        'error' => $msg,
                    ]);
                } else {
                    throw $e;
                }
            }
        }

        // 2) Fallback: `down` simples
        $this->shell->run([
            'php', 'artisan', 'down',
            '--retry=' . (string) $retry,
        ], $ctx);
    }

    public function rollback(UpdateContext $ctx): void
    {
        $this->shell->run(['php', 'artisan', 'up'], $ctx);
    }
}
