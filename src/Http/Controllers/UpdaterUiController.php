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
    public function index(UpdaterKernel $kernel)
    {
        $store = $kernel->stateStore();
        $store->ensureSchema();
        $lastRun = $store->lastRun();
        $runs = $store->recentRuns(20);

        return view('laravel-updater::index', [
            'status' => $kernel->status(),
            'lastRun' => $lastRun,
            'runs' => $runs,
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
        $dispatcher->triggerUpdate([
            'seed' => false,
            'seeders' => [],
            'allow_dirty' => false,
        ]);

        return back()->with('status', 'Atualização disparada com sucesso.');
    }

    public function triggerRollback(TriggerDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->triggerRollback();

        return back()->with('status', 'Rollback disparado com sucesso.');
    }
}
