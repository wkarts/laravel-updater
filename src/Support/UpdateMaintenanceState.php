<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Illuminate\Support\Facades\Cache;

/** Estado pertencente a uma execução; manutenção externa permanece intocada. */
final class UpdateMaintenanceState
{
    public const POLICY_MESSAGE = 'Política de manutenção da execução.';
    public const NATIVE_OWNER_KEY = 'updater_run_owner';

    public static function enabled(?ManagerStore $managerStore = null): bool
    {
        $branding = $managerStore?->branding() ?? [];
        $value = $branding['enter_maintenance_on_update_start']
            ?? config('updater.maintenance.enter_on_update_start', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public static function recordPolicy(array $context, ?StateStore $store): void
    {
        $runId = (int) ($context['run_id'] ?? 0);
        if ($store === null || $runId <= 0) {
            return;
        }

        $store->addRunLog($runId, 'info', self::POLICY_MESSAGE, [
            'event' => 'maintenance_policy',
            'enabled' => (bool) ($context['maintenance_enabled'] ?? false),
            'owned' => (bool) ($context['maintenance_owned'] ?? false),
            'mode' => (string) ($context['maintenance_mode'] ?? ''),
            'preexisting' => (bool) ($context['maintenance_preexisting'] ?? false),
            'ownership_id' => (string) ($context['maintenance_ownership_id'] ?? ''),
        ]);
    }

    public static function release(array &$context, ShellRunner $shell, bool $runArtisanUp = true, ?StateStore $store = null): void
    {
        if (!($context['maintenance_owned'] ?? false)) {
            return;
        }

        $identity = (string) ($context['maintenance_ownership_id'] ?? '');
        $mode = (string) ($context['maintenance_mode'] ?? '');
        $softState = Cache::get(SoftMaintenanceMiddleware::CACHE_KEY, false);
        $native = app()->maintenanceMode();
        $nativeOwner = $native->active() ? ($native->data()[self::NATIVE_OWNER_KEY] ?? '') : '';

        if ($mode === 'soft' && ($identity === '' || $softState === $identity)) {
            Cache::forget(SoftMaintenanceMiddleware::CACHE_KEY);
        } elseif ($mode === 'native' && ($identity === '' || $nativeOwner === $identity)) {
            $released = false;
            if ($runArtisanUp) {
                try {
                    $result = $shell->run(['php', 'artisan', 'up']);
                    $released = (int) ($result['exit_code'] ?? 1) === 0;
                } catch (\Throwable) {
                    // Fallback usa o driver nativo, inclusive quando não é arquivo.
                }
            }
            if (!$released) {
                app()->maintenanceMode()->deactivate();
                // O driver nativo remove o marcador down, mas o prerender
                // carregado antes do bootstrap também precisa ser retirado.
                @unlink(storage_path('framework/maintenance.php'));
            }
        }

        $context['maintenance'] = (bool) Cache::get(SoftMaintenanceMiddleware::CACHE_KEY, false)
            || app()->isDownForMaintenance();
        $context['maintenance_owned'] = false;
        self::recordPolicy($context, $store);
    }

    /** Recupera a política gravada antes da ativação, inclusive após fatal error. */
    public static function forRun(StateStore $store, int $runId): array
    {
        if ($runId <= 0) {
            return [];
        }

        $statement = $store->pdo()->prepare('SELECT context_json FROM updater_logs WHERE run_id = :run AND message = :message ORDER BY id DESC LIMIT 1');
        $statement->execute([':run' => $runId, ':message' => self::POLICY_MESSAGE]);
        $raw = $statement->fetchColumn();
        $policy = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($policy)) {
            return [
                'run_id' => $runId,
                'maintenance_owned' => (bool) ($policy['owned'] ?? false),
                'maintenance_mode' => (string) ($policy['mode'] ?? ''),
                'maintenance_enabled' => (bool) ($policy['enabled'] ?? false),
                'maintenance_ownership_id' => (string) ($policy['ownership_id'] ?? ''),
            ];
        }

        // Runs anteriores não gravavam ownership. Limpeza só com evidência de
        // entrada na etapa; falhas no backup/fila não pertencem à manutenção.
        $statement = $store->pdo()->prepare('SELECT 1 FROM updater_logs WHERE run_id = :run AND message = :message AND context_json LIKE :step LIMIT 1');
        $statement->execute([
            ':run' => $runId,
            ':message' => 'Etapa concluída com sucesso.',
            ':step' => '%"etapa":"maintenance_on"%',
        ]);
        if ($statement->fetchColumn() !== false) {
            return [
                'maintenance_owned' => true,
                'maintenance_mode' => app()->isDownForMaintenance() ? 'native' : 'soft',
            ];
        }

        return [];
    }
}
