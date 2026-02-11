<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UpdaterUiController extends Controller
{
    public function index(UpdaterKernel $kernel, Request $request)
    {
        $store = $kernel->stateStore();
        $store->ensureSchema();

        return view('laravel-updater::auth.dashboard', [
            'status' => $kernel->status(),
            'lastRun' => $store->lastRun(),
            'runs' => $store->recentRuns(20),
            'user' => $request->attributes->get('updater_user'),
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

    public function triggerUpdate(TriggerDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->triggerUpdate(['seed' => false, 'seeders' => [], 'allow_dirty' => false]);

        return back()->with('status', 'Atualização disparada com sucesso.');
    }

    public function triggerRollback(TriggerDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->triggerRollback();

        return back()->with('status', 'Rollback disparado com sucesso.');
    }
}
