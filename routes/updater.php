<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\AuthController;
use Argws\LaravelUpdater\Http\Controllers\ManagerController;
use Argws\LaravelUpdater\Http\Controllers\OperationsController;
use Argws\LaravelUpdater\Http\Controllers\UpdaterUiController;
use Illuminate\Support\Facades\Route;

if ((bool) config('updater.ui.enabled', true)) {
    $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');

    Route::group(['prefix' => $prefix], function (): void {
        Route::get('/assets/updater.css', [UpdaterUiController::class, 'assetCss'])->name('updater.asset.css');
        Route::get('/assets/updater.js', [UpdaterUiController::class, 'assetJs'])->name('updater.asset.js');
        Route::get('/assets/branding/logo', [UpdaterUiController::class, 'brandingLogo'])->name('updater.branding.logo');
        Route::get('/assets/branding/favicon', [UpdaterUiController::class, 'brandingFavicon'])->name('updater.branding.favicon');
        Route::get('/assets/branding/maintenance-logo', [UpdaterUiController::class, 'brandingMaintenanceLogo'])->name('updater.branding.maintenance_logo');
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

            Route::group(['middleware' => ['updater.auth', 'updater.authorize']], function (): void {
                Route::get('/', [UpdaterUiController::class, 'index'])->name('updater.index');
                Route::get('/profile', [AuthController::class, 'profile'])->name('updater.profile');
                Route::post('/profile/password', [AuthController::class, 'updatePassword'])->name('updater.profile.password');
                Route::post('/profile/2fa/enable', [AuthController::class, 'enableTwoFactor'])->name('updater.profile.2fa.enable');
                Route::post('/profile/2fa/disable', [AuthController::class, 'disableTwoFactor'])->name('updater.profile.2fa.disable');
                Route::post('/profile/2fa/recovery/regenerate', [AuthController::class, 'regenerateRecoveryCodes'])->name('updater.profile.2fa.recovery.regenerate');
                Route::get('/status', [UpdaterUiController::class, 'status'])->name('updater.status');
                Route::post('/check', [UpdaterUiController::class, 'check'])->name('updater.check');
                Route::post('/trigger-update', [UpdaterUiController::class, 'triggerUpdate'])->name('updater.trigger.update');
                Route::post('/trigger-rollback', [UpdaterUiController::class, 'triggerRollback'])->name('updater.trigger.rollback');
                Route::post('/maintenance/on', [UpdaterUiController::class, 'maintenanceOn'])->name('updater.maintenance.on');
                Route::post('/maintenance/off', [UpdaterUiController::class, 'maintenanceOff'])->name('updater.maintenance.off');

                Route::get('/runs/{id}', [OperationsController::class, 'runDetails'])->name('updater.runs.show');

                Route::post('/updates/execute', [OperationsController::class, 'executeUpdate'])->name('updater.updates.execute');
                Route::post('/runs/{id}/approve', [OperationsController::class, 'approveAndExecute'])->whereNumber('id')->name('updater.runs.approve');
                Route::get('/updates/progress/status', [OperationsController::class, 'updateProgressStatus'])->name('updater.updates.progress.status');

                Route::post('/backups/{type}/create', [OperationsController::class, 'backupNow'])->whereIn('type', ['database', 'snapshot', 'full'])->name('updater.backups.create');
                Route::get('/backups/log/download', [OperationsController::class, 'downloadUpdaterLog'])->name('updater.backups.log.download');
                Route::get('/backups/progress/status', [OperationsController::class, 'progressStatus'])->name('updater.backups.progress.status');
                Route::get('/backups/{id}/download', [OperationsController::class, 'downloadBackup'])->whereNumber('id')->name('updater.backups.download');
                Route::post('/backups/{id}/upload', [OperationsController::class, 'uploadBackup'])->whereNumber('id')->name('updater.backups.upload');
                Route::get('/backups/upload/progress/status', [OperationsController::class, 'uploadProgressStatus'])->name('updater.backups.upload.progress.status');
                Route::post('/backups/upload/cancel', [OperationsController::class, 'cancelUpload'])->name('updater.backups.upload.cancel');
                Route::delete('/backups/{id}', [OperationsController::class, 'deleteBackup'])->whereNumber('id')->name('updater.backups.delete');
                Route::post('/backups/cancel', [OperationsController::class, 'cancelBackup'])->name('updater.backups.cancel');
                Route::get('/backups/{id}/restore', [OperationsController::class, 'showRestoreForm'])->whereNumber('id')->name('updater.backups.restore.form');
                Route::post('/backups/{id}/restore', [OperationsController::class, 'restoreBackup'])->whereNumber('id')->name('updater.backups.restore');

                Route::get('/seeds', [OperationsController::class, 'seedsIndex'])->name('updater.seeds.index');
                Route::post('/seeds/reapply', [OperationsController::class, 'reapplySeed'])->name('updater.seeds.reapply');

                Route::get('/users', [ManagerController::class, 'usersIndex'])->name('updater.users.index');
                Route::get('/users/create', [ManagerController::class, 'usersCreate'])->name('updater.users.create');
                Route::post('/users', [ManagerController::class, 'usersStore'])->name('updater.users.store');
                Route::get('/users/{id}/edit', [ManagerController::class, 'usersEdit'])->name('updater.users.edit');
                Route::put('/users/{id}', [ManagerController::class, 'usersUpdate'])->name('updater.users.update');
                Route::delete('/users/{id}', [ManagerController::class, 'usersDelete'])->name('updater.users.delete');
                Route::post('/users/{id}/2fa/reset', [ManagerController::class, 'usersResetTwoFactor'])->name('updater.users.2fa.reset');

                Route::get('/profiles', [ManagerController::class, 'profilesIndex'])->name('updater.profiles.index');
                Route::get('/profiles/create', [ManagerController::class, 'profilesCreate'])->name('updater.profiles.create');
                Route::post('/profiles', [ManagerController::class, 'profilesStore'])->name('updater.profiles.store');
                Route::get('/profiles/{id}/edit', [ManagerController::class, 'profilesEdit'])->name('updater.profiles.edit');
                Route::put('/profiles/{id}', [ManagerController::class, 'profilesUpdate'])->name('updater.profiles.update');
                Route::delete('/profiles/{id}', [ManagerController::class, 'profilesDelete'])->name('updater.profiles.delete');
                Route::post('/profiles/{id}/activate', [ManagerController::class, 'profilesActivate'])->name('updater.profiles.activate');

                Route::get('/settings', [ManagerController::class, 'settingsIndex'])->name('updater.settings.index');
                Route::post('/settings/branding', [ManagerController::class, 'saveBranding'])->name('updater.settings.branding.save');
                Route::delete('/settings/branding/{asset}', [ManagerController::class, 'removeBrandingAsset'])->whereIn('asset', ['logo', 'favicon', 'maintenance-logo'])->name('updater.settings.branding.asset.remove');
                Route::post('/settings/branding/reset', [ManagerController::class, 'resetBranding'])->name('updater.settings.branding.reset');
                Route::post('/settings/tokens', [ManagerController::class, 'createApiToken'])->name('updater.settings.tokens.create');
                Route::delete('/settings/tokens/{id}', [ManagerController::class, 'revokeApiToken'])->name('updater.settings.tokens.revoke');
                Route::post('/settings/backup-upload', [ManagerController::class, 'saveBackupUploadSettings'])->name('updater.settings.backup-upload.save');

                Route::post('/sources/save', [ManagerController::class, 'saveSource'])->name('updater.sources.save');
                Route::post('/sources/{id}/activate', [ManagerController::class, 'activateSource'])->name('updater.sources.activate');
                Route::delete('/sources/{id}', [ManagerController::class, 'deleteSource'])->name('updater.sources.delete');
                Route::post('/sources/test', [ManagerController::class, 'testSourceConnection'])->name('updater.sources.test');

                Route::get('/{section}', [ManagerController::class, 'section'])->whereIn('section', ['updates', 'runs', 'sources', 'profiles', 'backups', 'logs', 'security', 'admin-users', 'settings', 'seeds'])->name('updater.section');
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
            Route::post('/maintenance/on', [UpdaterUiController::class, 'maintenanceOn'])->name('updater.maintenance.on');
            Route::post('/maintenance/off', [UpdaterUiController::class, 'maintenanceOff'])->name('updater.maintenance.off');
            Route::get('/{section}', [ManagerController::class, 'section'])->whereIn('section', ['updates', 'runs', 'sources', 'profiles', 'backups', 'logs', 'security', 'admin-users', 'settings', 'seeds'])->name('updater.section');
        });
    }
}
