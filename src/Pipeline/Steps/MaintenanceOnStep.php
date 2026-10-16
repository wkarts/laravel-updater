<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Http\Middleware\SoftMaintenanceMiddleware;
use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\MaintenanceMode;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateMaintenanceState;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Support\Facades\Cache;

class MaintenanceOnStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly ?MaintenanceMode $maintenanceMode = null,
        private readonly ?ManagerStore $managerStore = null,
        private readonly ?StateStore $store = null
    )
    {
    }

    public function name(): string { return 'maintenance_on'; }
    public function shouldRun(array $context): bool
    {
        return !(bool) ($context['options']['dry_run'] ?? false)
            && UpdateMaintenanceState::enabled($this->managerStore);
    }

    public function handle(array &$context): void
    {
        // A chamada direta também deve respeitar a política, sem depender da pipeline.
        if (!$this->shouldRun($context)) {
            $context['maintenance_enabled'] = false;
            $context['maintenance_owned'] = false;
            return;
        }

        $softBefore = (bool) Cache::get(SoftMaintenanceMiddleware::CACHE_KEY, false);
        $nativeBefore = app()->isDownForMaintenance();
        $mode = $nativeBefore ? 'native' : ($softBefore ? 'soft' : (
            (bool) config('updater.maintenance.soft_enabled', true) ? 'soft' : 'native'
        ));
        $context['maintenance_mode'] = $mode;
        $context['maintenance_enabled'] = true;
        $context['maintenance_owned'] = !$softBefore && !$nativeBefore;
        $context['maintenance_preexisting'] = $softBefore || $nativeBefore;
        $context['maintenance'] = $softBefore || $nativeBefore;
        $context['maintenance_ownership_id'] = bin2hex(random_bytes(16));
        UpdateMaintenanceState::recordPolicy($context, $this->store);

        if (!$context['maintenance_owned']) {
            return;
        }

        // Manutenção "soft" (padrão): não usa artisan down.
        // Motivo: evita travar o próprio updater e dribla inconsistências da opção --except.
        if ((bool) config('updater.maintenance.soft_enabled', true)) {
            Cache::forever(SoftMaintenanceMiddleware::CACHE_KEY, $context['maintenance_ownership_id']);
            $context['maintenance'] = true;
            $context['maintenance_mode'] = 'soft';
            return;
        }

        try {
            $this->enterNativeMaintenance($context);
        } catch (\Throwable $exception) {
            $this->rollback($context);
            throw $exception;
        }
    }

    private function enterNativeMaintenance(array &$context): void
    {
        // Prefer a safe, package-provided view by default.
        // If the host app wants to keep its own errors::503, it can set UPDATER_MAINTENANCE_VIEW=errors::503
        // or updater.maintenance.render_view accordingly.
        $preferred = (string) config('updater.maintenance.render_view', (string) env('UPDATER_MAINTENANCE_VIEW', 'laravel-updater::maintenance'));

        $candidates = [];
        if (!empty($preferred)) { $candidates[] = $preferred; }

        // Keep legacy compatibility: some installations rely on errors::503.
        // We try it as a fallback if not already chosen.
        if (!in_array('errors::503', $candidates, true)) {
            $candidates[] = 'errors::503';
        }

        $entered = false;

        $downEnv = [
            'UPDATER_MAINTENANCE_OWNER' => $context['maintenance_ownership_id'],
            'REQUEST_URI' => '/',
            'HTTP_HOST' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost',
            'SERVER_NAME' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost',
            'SERVER_PORT' => (string) (parse_url((string) config('app.url', 'http://localhost'), PHP_URL_PORT) ?: 80),
            'HTTPS' => str_starts_with((string) config('app.url', 'http://localhost'), 'https://') ? 'on' : 'off',
        ];

        $includeExcept = true;

        foreach ($candidates as $view) {
            try {
                $this->shellRunner->runOrFail($this->downCommand($view, $includeExcept), env: $downEnv);
                $entered = true;
                break;
            } catch (\Throwable $e) {
                if ($includeExcept && $this->hasExceptOptionError($e)) {
    // O host app/framework não suporta --except (varia por versão/stack).
    // Estratégia corretiva:
    // 1) Entra em manutenção SEM --except (para realmente bloquear a aplicação),
    // 2) Em seguida, ajusta storage/framework/down para incluir as rotas do updater no array "except".
    $includeExcept = false;

    try {
        $this->shellRunner->runOrFail($this->downCommand($view, false), env: $downEnv);
        $entered = true;
        break;
    } catch (\Throwable $e2) {
        $context['maintenance_on_error'][] = [
            'view' => $view,
            'error' => $e2->getMessage(),
        ];
        continue;
    }
}

                // Try next candidate (common failure: host view expects REQUEST_URI in CLI).
                $context['maintenance_on_error'][] = [
                    'view' => $view,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (!$entered) {
            // Last resort: enter maintenance without custom render.
            $this->shellRunner->runOrFail($this->downCommand(null, $includeExcept), env: $downEnv);
        }

        // Hard guarantee: keep updater routes accessible while in maintenance.
        // Laravel 11/12 may fail to persist --except into storage/framework/down in some environments.
        // We patch the down file defensively to ensure the updater prefix is always excluded.
        $this->ensureUpdaterExceptInDownFile($context);

        $context['maintenance'] = true;
    }

    private function ensureUpdaterExceptInDownFile(array &$context): void
    {
        if (!function_exists('base_path')) {
            return;
        }

        $downFile = rtrim((string) base_path('storage/framework/down'));
        if ($downFile === '' || !is_file($downFile)) {
            return;
        }

        $raw = (string) @file_get_contents($downFile);
        if (trim($raw) === '') {
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        $except = $data['except'] ?? [];
        if (!is_array($except)) {
            $except = [];
        }

        // Get updater prefix from config, with safe fallbacks.
        $prefix = trim((string) (function_exists('config') ? config('updater.ui.prefix', env('UPDATER_UI_PREFIX', '_updater')) : (string) env('UPDATER_UI_PREFIX', '_updater')), '/');
        if ($prefix === '') {
            return;
        }

        $required = [
            '/' . $prefix,
            '/' . $prefix . '/*',
            $prefix,
            $prefix . '/*',
        ];

        $set = [];
        foreach ($except as $item) {
            if (!is_string($item)) {
                continue;
            }
            $val = trim($item);
            if ($val === '') {
                continue;
            }
            $set[$val] = true;
        }

        $changed = false;
        foreach ($required as $path) {
            if (!isset($set[$path])) {
                $set[$path] = true;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $data['except'] = array_values(array_keys($set));

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        $ok = @file_put_contents($downFile, $encoded);
        if ($ok === false) {
            $context['maintenance_on_warning'][] = 'Falha ao persistir except no arquivo storage/framework/down.';
        } else {
            $context['maintenance_on_warning'][] = 'Arquivo storage/framework/down ajustado para garantir except do updater.';
        }
    }


    /** @return array<int,string> */
    private function downCommand(?string $view = null, bool $includeExcept = true): array
    {
        if ($this->maintenanceMode !== null) {
            return $this->maintenanceMode->downCommand($view, $includeExcept);
        }

        $command = ['php', 'artisan', 'down'];
        if ($view !== null && trim($view) !== '') {
            $command[] = '--render=' . trim($view);
        }

        return $command;
    }


    private function hasExceptOptionError(\Throwable $e): bool
    {
        if ($this->maintenanceMode !== null) {
            return $this->maintenanceMode->hasExceptOptionError($e);
        }

        return str_contains(mb_strtolower($e->getMessage()), '--except');
    }

    public function rollback(array &$context): void
    {
        UpdateMaintenanceState::release($context, $this->shellRunner, true, $this->store);
    }
}
