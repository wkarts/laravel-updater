<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\AuthController;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Route;

if ((bool) config('updater.ui.enabled', true)) {
    $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
    $authEnabled = (bool) config('updater.ui.auth.enabled', true);

    Route::group(['prefix' => $prefix], function () use ($authEnabled): void {
        if ($authEnabled) {
            Route::middleware(['web'])->group(function (): void {
                Route::get('/login', [AuthController::class, 'loginForm'])->name('updater.login');
                Route::post('/login', [AuthController::class, 'login'])->name('updater.login.submit');
                Route::get('/2fa', [AuthController::class, 'twoFactorForm'])->name('updater.2fa');
                Route::post('/2fa', [AuthController::class, 'twoFactor'])->name('updater.2fa.submit');
            });

            Route::middleware(['web', 'updater.auth'])->group(function (): void {
                Route::get('/', [UpdaterUiController::class, 'index'])->name('updater.index');
                Route::get('/status', [UpdaterUiController::class, 'status'])->name('updater.status');
                Route::post('/check', [UpdaterUiController::class, 'check'])->name('updater.check');
                Route::post('/trigger-update', [UpdaterUiController::class, 'triggerUpdate'])->name('updater.trigger.update');
                Route::post('/trigger-rollback', [UpdaterUiController::class, 'triggerRollback'])->name('updater.trigger.rollback');
                Route::get('/profile', [AuthController::class, 'profile'])->name('updater.profile');
                Route::post('/profile', [AuthController::class, 'updateProfile'])->name('updater.profile.update');
                Route::post('/logout', [AuthController::class, 'logout'])->name('updater.logout');
            });

            return;
        }

        Route::middleware(config('updater.ui.middleware', ['web', 'auth']))->group(function (): void {
            Route::get('/', [UpdaterUiController::class, 'index'])->name('updater.index');
            Route::get('/status', [UpdaterUiController::class, 'status'])->name('updater.status');
            Route::post('/check', [UpdaterUiController::class, 'check'])->name('updater.check');
            Route::post('/trigger-update', [UpdaterUiController::class, 'triggerUpdate'])->name('updater.trigger.update');
            Route::post('/trigger-rollback', [UpdaterUiController::class, 'triggerRollback'])->name('updater.trigger.rollback');
        });
    });
}
