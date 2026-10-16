<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
if ($prefix !== '') {
    // HMAC endpoint intentionally has no session/CSRF middleware, exactly like the existing machine API.
    Route::post($prefix . '/api/webhooks/{endpointId}', [WebhookController::class, 'receive'])
        ->whereNumber('endpointId')->name('updater.api.webhooks.receive');
}

if ((bool) config('updater.ui.enabled', true) && $prefix !== '') {
    $middleware = (bool) config('updater.ui.auth.enabled', false)
        ? ['web', 'updater.auth', 'updater.authorize']
        : (array) config('updater.ui.middleware', ['web', 'auth']);
    Route::group(['prefix' => $prefix, 'middleware' => $middleware], static function (): void {
        Route::get('/webhooks', [WebhookController::class, 'index'])->name('updater.settings.webhooks.index');
        Route::post('/webhooks/endpoints', [WebhookController::class, 'save'])->name('updater.settings.webhooks.save');
        Route::post('/webhooks/endpoints/{id}/rotate-secret', [WebhookController::class, 'rotate'])->whereNumber('id')->name('updater.settings.webhooks.rotate');
        Route::post('/webhooks/endpoints/{id}/test', [WebhookController::class, 'test'])->whereNumber('id')->name('updater.settings.webhooks.test');
        Route::delete('/webhooks/endpoints/{id}', [WebhookController::class, 'delete'])->whereNumber('id')->name('updater.settings.webhooks.delete');
        Route::post('/webhooks/deliveries/{id}/retry', [WebhookController::class, 'retry'])->whereNumber('id')->name('updater.settings.webhooks.retry');
    });
}
