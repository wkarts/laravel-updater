<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\AuthController;
use Argws\LaravelUpdater\Http\Controllers\ManagerController;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Route;

if ((bool) config('updater.ui.enabled', true)) {
    $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');

    Route::group(['prefix' => $prefix], function (): void {
        Route::get('/assets/updater.css', [UpdaterUiController::class, 'assetCss'])->name('updater.asset.css');
        Route::get('/assets/updater.js', [UpdaterUiController::class, 'assetJs'])->name('updater.asset.js');
        Route::get('/assets/branding/logo', [UpdaterUiController::class, 'brandingLogo'])->name('updater.branding.logo');
        Route::get('/assets/branding/favicon', [UpdaterUiController::class, 'brandingFavicon'])->name('updater.branding.favicon');
        Route::post('/api/trigger', [UpdaterUiController::class, 'apiTrigger'])->name('updater.api.trigger');
    });

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

                Route::get('/{section}', [ManagerController::class, 'section'])->whereIn('section', ['updates', 'runs', 'sources', 'profiles', 'backups', 'logs', 'security', 'admin-users', 'settings'])->name('updater.section');
                Route::post('/settings/branding', [ManagerController::class, 'saveBranding'])->name('updater.settings.branding.save');
                Route::post('/settings/branding/reset', [ManagerController::class, 'resetBranding'])->name('updater.settings.branding.reset');
                Route::post('/sources/save', [ManagerController::class, 'saveSource'])->name('updater.sources.save');
                Route::post('/sources/{id}/activate', [ManagerController::class, 'activateSource'])->name('updater.sources.activate');
                Route::post('/sources/test', [ManagerController::class, 'testSourceConnection'])->name('updater.sources.test');
                Route::post('/profiles/save', [ManagerController::class, 'saveProfile'])->name('updater.profiles.save');
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
            Route::get('/{section}', [ManagerController::class, 'section'])->whereIn('section', ['updates', 'runs', 'sources', 'profiles', 'backups', 'logs', 'security', 'admin-users', 'settings'])->name('updater.section');
        });
    }
}
