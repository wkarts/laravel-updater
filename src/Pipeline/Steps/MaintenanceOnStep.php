<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\MaintenanceMode;
use Argws\LaravelUpdater\Support\ShellRunner;

class MaintenanceOnStep implements PipelineStepInterface
{
    public function __construct(private readonly ShellRunner $shellRunner, private readonly ?MaintenanceMode $maintenanceMode = null)
    {
    }

    public function name(): string { return 'maintenance_on'; }
    public function shouldRun(array $context): bool { return true; }

    public function handle(array &$context): void
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
                    $includeExcept = false;
                    try {
                        $this->shellRunner->runOrFail($this->downCommand($view, false), env: $downEnv);
                        $entered = true;
                        break;
                    } catch (\Throwable $retryError) {
                        $context['maintenance_on_error'][] = [
                            'view' => $view,
                            'error' => $retryError->getMessage(),
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

        $context['maintenance'] = true;
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
