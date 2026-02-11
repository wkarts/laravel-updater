<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\AuthController;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Route;

if ((bool) config('updater.ui.enabled', true)) {
    $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');

    if ((bool) config('updater.ui.auth.enabled', false)) {
        Route::group([
            'prefix' => $prefix,
            'middleware' => ['web'],
        ], function (): void {
            Route::get('/login', [AuthController::class, 'showLogin'])->name('updater.login');
            Route::post('/login', [AuthController::class, 'login'])->name('updater.login.submit');
            Route::get('/2fa', [AuthController::class, 'showTwoFactor'])->name('updater.2fa');
            Route::post('/2fa', [AuthController::class, 'verifyTwoFactor'])->name('updater.2fa.verify');
            Route::post('/logout', [AuthController::class, 'logout'])->name('updater.logout');

            Route::group(['middleware' => ['updater.auth']], function (): void {
                Route::get('/', [UpdaterUiController::class, 'index'])->name('updater.index');
                Route::get('/profile', [AuthController::class, 'profile'])->name('updater.profile');
                Route::post('/profile/password', [AuthController::class, 'updatePassword'])->name('updater.profile.password');
                Route::post('/profile/2fa/enable', [AuthController::class, 'enableTwoFactor'])->name('updater.profile.2fa.enable');
                Route::post('/profile/2fa/disable', [AuthController::class, 'disableTwoFactor'])->name('updater.profile.2fa.disable');
                Route::get('/status', [UpdaterUiController::class, 'status'])->name('updater.status');
                Route::post('/check', [UpdaterUiController::class, 'check'])->name('updater.check');
                Route::post('/trigger-update', [UpdaterUiController::class, 'triggerUpdate'])->name('updater.trigger.update');
                Route::post('/trigger-rollback', [UpdaterUiController::class, 'triggerRollback'])->name('updater.trigger.rollback');
            });
        });
    } else {
        Route::group([
            'prefix' => $prefix,
            'middleware' => config('updater.ui.middleware', ['web', 'auth']),
        ], function (): void {
            Route::get('/', [UpdaterUiController::class, 'index'])->name('updater.index');
            Route::get('/status', [UpdaterUiController::class, 'status'])->name('updater.status');
            Route::post('/check', [UpdaterUiController::class, 'check'])->name('updater.check');
            Route::post('/trigger-update', [UpdaterUiController::class, 'triggerUpdate'])->name('updater.trigger.update');
            Route::post('/trigger-rollback', [UpdaterUiController::class, 'triggerRollback'])->name('updater.trigger.rollback');
        });
    }
}
