<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UpdaterUiController extends Controller
{
    public function __construct(private readonly ManagerStore $managerStore)
    {
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

    public function triggerUpdate(Request $request, TriggerDispatcher $dispatcher): RedirectResponse
    {
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

    public function triggerRollback(TriggerDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->triggerRollback();

        return back()->with('status', 'Rollback disparado com sucesso.');
    }

    public function apiTrigger(Request $request, TriggerDispatcher $dispatcher): JsonResponse
    {
        $token = (string) $request->bearerToken();
        if ($token === '') {
            $token = (string) $request->header('X-Updater-Token', '');
        }

        if ($token === '' || !$this->managerStore->validateApiToken($token)) {
            return response()->json(['ok' => false, 'message' => 'Token inválido'], 401);
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
