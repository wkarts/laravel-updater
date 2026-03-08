<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\MaintenanceMode;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\Totp;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UpdaterUiController extends Controller
{
    public function __construct(
        private readonly ManagerStore $managerStore,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly Totp $totp,
        private readonly AuthStore $authStore
    ) {
    }

    public function index(UpdaterKernel $kernel)
    {
        $store = $kernel->stateStore();
        $store->ensureSchema();
        $lastRun = $store->lastRun();
        $runs = $store->recentRuns(20);

        try {
            $status = $kernel->status();
        } catch (Throwable $e) {
            $status = [
                'enabled' => (bool) config('updater.enabled', true),
                'mode' => (string) config('updater.mode', 'inplace'),
                'channel' => (string) config('updater.channel', 'stable'),
                'revision' => 'N/A',
                'last_run' => $lastRun,
                'warning' => 'Não foi possível carregar status completo: ' . $e->getMessage(),
            ];
        }

        return view('laravel-updater::dashboard', [
            'status' => $status,
            'lastRun' => $lastRun,
            'runs' => $runs,
            'branding' => $this->managerStore->resolvedBranding(),
            'activeProfile' => $this->managerStore->activeProfile(),
            'activeSource' => $this->managerStore->activeSource(),
            'versionBar' => $this->resolveVersionBarData($kernel, $status),
        ]);
    }

    public function check(UpdaterKernel $kernel, Request $request): JsonResponse
    {
        return response()->json($kernel->check((bool) $request->boolean('allow_dirty')));
    }

    public function status(UpdaterKernel $kernel): JsonResponse
    {
        return response()->json($kernel->status());
    }

    public function triggerUpdate(Request $request, TriggerDispatcher $dispatcher, UpdaterKernel $kernel): RedirectResponse
    {
        if ($kernel->stateStore()->hasActiveRun()) {
            return back()->withErrors(["update" => "Já existe uma execução em andamento. Aguarde finalizar para disparar outra."]);
        }

        $activeProfile = $this->managerStore->activeProfile();
        $preUpdateCommands = $this->parseCommands((string) ($activeProfile['pre_update_commands'] ?? ''));
        $postUpdateCommands = $this->parseCommands((string) ($activeProfile['post_update_commands'] ?? ''));

        $dispatcher->triggerUpdate([
            'seed' => (bool) ($activeProfile['seed'] ?? false),
            'seeders' => $request->filled('seed') ? [$request->string('seed')->toString()] : [],
            'pre_update_commands' => $preUpdateCommands,
            'post_update_commands' => $postUpdateCommands,
            'allow_dirty' => false,
            'dry_run' => (bool) ($activeProfile['dry_run'] ?? false),
            'rollback_on_fail' => (bool) ($activeProfile['rollback_on_fail'] ?? true),
            'profile_id' => $activeProfile['id'] ?? null,
            'source_id' => $this->managerStore->activeSource()['id'] ?? null,
            'check_only' => $request->boolean('check_only'),
            'allow_http' => true,
        ]);

        return back()->with('status', 'Atualização disparada com sucesso.');
    }

    /** @return array<int,string> */
    private function parseCommands(string $raw): array
    {
        $commands = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $commands[] = $line;
        }

        return array_values(array_unique($commands));
    }

    public function triggerRollback(TriggerDispatcher $dispatcher, UpdaterKernel $kernel): RedirectResponse
    {
        if ($kernel->stateStore()->hasActiveRun()) {
            return back()->withErrors(["rollback" => "Já existe uma execução em andamento. Aguarde finalizar para disparar rollback."]);
        }

        $dispatcher->triggerRollback();

        return back()->with('status', 'Rollback disparado com sucesso.');
    }


    public function maintenanceOn(Request $request, ShellRunner $shellRunner): RedirectResponse
    {
        $this->validateMaintenanceConfirmation($request);

        $view = (string) config('updater.maintenance.render_view', 'laravel-updater::maintenance');

        try {
            $shellRunner->runOrFail($this->maintenanceMode->downCommand($view, true));
        } catch (\Throwable $e) {
            if ($this->maintenanceMode->hasExceptOptionError($e)) {
                $shellRunner->runOrFail($this->maintenanceMode->downCommand($view, false));
            } else {
                throw $e;
            }
        }

        return back()->with('status', 'Modo manutenção habilitado com sucesso.');
    }

    public function maintenanceOff(Request $request, ShellRunner $shellRunner): RedirectResponse
    {
        $this->validateMaintenanceConfirmation($request);
        $shellRunner->runOrFail(['php', 'artisan', 'up']);

        return back()->with('status', 'Modo manutenção desabilitado com sucesso.');
    }

    private function validateMaintenanceConfirmation(Request $request): void
    {
        $data = $request->validate([
            'maintenance_confirmation' => ['required', 'string'],
            'maintenance_2fa_code' => ['nullable', 'string'],
        ], [
            'maintenance_confirmation.required' => 'Confirme a ação digitando MANUTENCAO.',
        ]);

        if (mb_strtoupper(trim((string) $data['maintenance_confirmation'])) !== 'MANTENCAO') {
            throw \Illuminate\Validation\ValidationException::withMessages(['maintenance_confirmation' => 'Confirmação inválida. Digite MANTENCAO para prosseguir.']);
        }

        if (!(bool) config('updater.ui.auth.enabled', false)) {
            return;
        }

        $user = (array) $request->attributes->get('updater_user', []);
        if (!((bool) ($user['totp_enabled'] ?? false))) {
            return;
        }

        $code = trim((string) ($data['maintenance_2fa_code'] ?? ''));
        if ($code === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['maintenance_2fa_code' => 'Informe o código 2FA para confirmar esta ação.']);
        }

        if (!$this->totp->verify((string) ($user['totp_secret'] ?? ''), $code) && !$this->authStore->consumeRecoveryCode((int) ($user['id'] ?? 0), $code)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['maintenance_2fa_code' => 'Código 2FA/recovery inválido.']);
        }
    }


    /**
     * Retrocompatibilidade com instalações antigas/publicadas que ainda chamam este método.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public function resolveVersionBarData(UpdaterKernel $kernel, array $status = []): array
    {
        $activeSource = $this->managerStore->activeSource();
        $available = 'n/d';
        $installed = 'n/d';

        if (class_exists('Composer\\InstalledVersions') && \Composer\InstalledVersions::isInstalled('argws/laravel-updater')) {
            $installed = \Composer\InstalledVersions::getPrettyVersion('argws/laravel-updater') ?: 'n/d';
        }

        $cacheKey = sprintf('updater:version_bar:%s:%s', (string) ($activeSource['id'] ?? 'none'), (string) ($status['channel'] ?? config('updater.channel', 'stable')));
        try {
            $available = (string) Cache::remember($cacheKey, now()->addMinutes(3), static function () use ($kernel): string {
                $check = $kernel->check(true);

                return (string) ($check['latest_tag'] ?? $check['remote'] ?? 'n/d');
            });
        } catch (\Throwable $e) {
            $available = (string) Cache::get($cacheKey, 'n/d');
        }

        return [
            'enabled' => true,
            'position' => 'top',
            'updater' => [
                'installed' => $installed,
                'latest' => $available,
            ],
            'application' => [
                'framework_version' => app()->version(),
                'git_revision' => (string) ($status['revision'] ?? 'n/d'),
                'git_tag' => (string) ($status['last_run']['revision_after'] ?? ''),
                'channel' => (string) ($status['channel'] ?? config('updater.channel', 'stable')),
                'source_name' => (string) ($activeSource['name'] ?? 'n/d'),
                'source_type' => (string) ($activeSource['type'] ?? 'n/d'),
            ],
        ];
    }

    public function apiTrigger(Request $request, TriggerDispatcher $dispatcher): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request)) !== null) {
            return $authError;
        }

        $options = [
            'profile_id' => $request->input('profile_id'),
            'dry_run' => (bool) $request->boolean('dry_run'),
            'seed' => (string) $request->input('seed', ''),
            'sql_patch' => (string) $request->input('sql_patch', ''),
            'triggered_via' => 'api',
            'allow_http' => true,
        ];

        $dispatcher->triggerUpdate($options);

        return response()->json([
            'queued' => true,
            'run_id' => null,
            'options' => $options,
        ]);
    }

    public function apiStatus(Request $request, UpdaterKernel $kernel): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request)) !== null) {
            return $authError;
        }

        return response()->json([
            'ok' => true,
            'data' => $kernel->status(),
            'requested_at' => now()->toIso8601String(),
        ]);
    }

    public function apiCheck(Request $request, UpdaterKernel $kernel): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request)) !== null) {
            return $authError;
        }

        return response()->json([
            'ok' => true,
            'data' => $kernel->check((bool) $request->boolean('allow_dirty', true)),
            'requested_at' => now()->toIso8601String(),
        ]);
    }

    public function apiRuns(Request $request, UpdaterKernel $kernel): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request)) !== null) {
            return $authError;
        }

        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $runs = $kernel->stateStore()->recentRuns($limit);

        return response()->json([
            'ok' => true,
            'count' => count($runs),
            'data' => $runs,
            'requested_at' => now()->toIso8601String(),
        ]);
    }

    private function validateApiRequest(Request $request): ?JsonResponse
    {
        $token = (string) ($request->bearerToken() ?: $request->header('X-Updater-Token', ''));
        if ($token === '' || !$this->managerStore->validateApiToken($token)) {
            return response()->json(['ok' => false, 'message' => 'Token inválido'], 401);
        }

        return null;
    }

    public function assetCss()
    {
        $candidates = [
            __DIR__ . '/../../../../resources/assets/updater.css',
            public_path('vendor/laravel-updater/updater.css'),
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'public, max-age=3600',
                    'Content-Type' => 'text/css; charset=UTF-8',
                ]);
            }
        }

        abort(404, 'Asset CSS do updater não encontrado.');
    }

    public function assetJs()
    {
        $candidates = [
            __DIR__ . '/../../../../resources/assets/updater.js',
            public_path('vendor/laravel-updater/updater.js'),
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'public, max-age=3600',
                    'Content-Type' => 'application/javascript; charset=UTF-8',
                ]);
            }
        }

        abort(404, 'Asset JS do updater não encontrado.');
    }

    public function brandingLogo()
    {
        $branding = $this->managerStore->branding();
        if ($branding === null || empty($branding['logo_path']) || !Storage::exists((string) $branding['logo_path'])) {
            abort(404);
        }

        return response()->file(Storage::path((string) $branding['logo_path']), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }


    public function brandingMaintenanceLogo()
    {
        $branding = $this->managerStore->branding();
        if ($branding === null || empty($branding['maintenance_logo_path']) || !Storage::exists((string) $branding['maintenance_logo_path'])) {
            abort(404);
        }

        return response()->file(Storage::path((string) $branding['maintenance_logo_path']), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function brandingFavicon()
    {
        $branding = $this->managerStore->branding();
        if ($branding === null || empty($branding['favicon_path']) || !Storage::exists((string) $branding['favicon_path'])) {
            abort(404);
        }

        return response()->file(Storage::path((string) $branding['favicon_path']), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
