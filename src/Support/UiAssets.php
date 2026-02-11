<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class UiAssets
{
    public static function cssUrl(): string
    {
        $publishedPath = public_path('vendor/laravel-updater/updater.css');

        if (is_file($publishedPath)) {
            return asset('vendor/laravel-updater/updater.css');
        }

        return route('updater.asset.css');
    }

    public static function jsUrl(): string
    {
        $publishedPath = public_path('vendor/laravel-updater/updater.js');

        if (is_file($publishedPath)) {
            return asset('vendor/laravel-updater/updater.js');
        }

        return route('updater.asset.js');
    }

    public static function brandingLogoUrl(): string
    {
        return route('updater.branding.logo');
    }

    public static function faviconUrl(): string
    {
        return route('updater.branding.favicon');
    }
}
