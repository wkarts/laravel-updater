<?php

declare(strict_types=1);

use Argws\LaravelUpdater\Support\SpaAssets;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
if ($prefix !== '') {
    Route::get($prefix . '/assets/spa/{file}', [SpaAssets::class, 'serve'])
        ->where('file', '[A-Za-z0-9_\-\/.]+')->name('updater.spa.asset');
}
// No wildcard SPA fallback: every navigable screen continues through its original backend route.
