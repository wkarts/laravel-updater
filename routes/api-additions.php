<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Route;

// Loaded once by the package route file. Restricted to the configured updater prefix.
if ((bool) config('updater.ui.enabled', true)) {
    $apiPrefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
    Route::prefix($apiPrefix)->group(function (): void {
        Route::get('/api/release-notes', [UpdaterUiController::class, 'apiReleaseNotes'])->name('updater.api.release-notes');
        Route::get('/api/openapi.json', [UpdaterUiController::class, 'apiOpenApi'])->name('updater.api.openapi');
    });
    $notesMiddleware = (bool) config('updater.ui.auth.enabled', false)
        ? ['web', 'updater.auth', 'updater.authorize']
        : (array) config('updater.ui.middleware', ['web', 'auth']);
    Route::prefix($apiPrefix)->middleware($notesMiddleware)->group(function (): void {
        Route::get('/release-notes', [UpdaterUiController::class, 'releaseNotes'])->name('updater.release-notes');
        Route::get('/api-docs', [UpdaterUiController::class, 'apiDocumentation'])->name('updater.settings.api-docs');
    });
}
