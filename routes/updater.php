<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Route;

if ((bool) config('updater.ui.enabled', true)) {
    Route::group([
        'prefix' => config('updater.ui.prefix', '_updater'),
        'middleware' => config('updater.ui.middleware', ['web', 'auth']),
    ], function (): void {
        Route::get('/', [UpdaterUiController::class, 'index'])->name('updater.index');
        Route::get('/status', [UpdaterUiController::class, 'status'])->name('updater.status');
        Route::post('/check', [UpdaterUiController::class, 'check'])->name('updater.check');
        Route::post('/trigger-update', [UpdaterUiController::class, 'triggerUpdate'])->name('updater.trigger.update');
        Route::post('/trigger-rollback', [UpdaterUiController::class, 'triggerRollback'])->name('updater.trigger.rollback');
    });
}
