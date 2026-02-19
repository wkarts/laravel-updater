<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\MaintenanceMode;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;

class MaintenanceOnStep implements PipelineStepInterface
{
    public function __construct(
        private readonly ShellRunner $shellRunner,
        private readonly StateStore $stateStore,
        private readonly ?MaintenanceMode $maintenanceMode = null,
    )
    {
    }

    public function name(): string { return 'maintenance_on'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
    {
        // IMPORTANT:
        // We do NOT use Laravel's native `artisan down` here.
        // Native maintenance mode blocks ALL routes (including the updater UI), and `--except` is not
        // available/consistent across Laravel 10/11/12 stacks.
        //
        // Instead, we enable a "soft maintenance" mode controlled by this package:
        // - blocks the host app
        // - keeps the updater prefix always accessible

        $preferredView = (string) config('updater.maintenance.render_view', (string) env('UPDATER_MAINTENANCE_VIEW', 'laravel-updater::maintenance'));
        if ($preferredView === '') {
            $preferredView = 'laravel-updater::maintenance';
        }

        $this->stateStore->set('soft_maintenance', [
            'enabled' => true,
            'started_at' => now()->toIso8601String(),
            'title' => (string) config('updater.maintenance.title', (string) env('UPDATER_MAINTENANCE_TITLE', 'Atualização em andamento')),
            'message' => (string) config('updater.maintenance.message', (string) env('UPDATER_MAINTENANCE_MESSAGE', 'Estamos atualizando o sistema. Volte em alguns minutos.')),
            'view' => $preferredView,
        ]);

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
        $this->shellRunner->run(['php', 'artisan', 'up']);
    }
}
