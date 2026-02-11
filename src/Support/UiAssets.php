<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class UiAssets
{
    public static function cssUrl(): string
    {
        $publishedPath = public_path('vendor/laravel-updater/updater.css');
        $strategy = (string) config('updater.ui.assets.strategy', 'route');

        if ($strategy === 'published' || ($strategy === 'auto' && is_file($publishedPath))) {
            return self::withVersion(asset('vendor/laravel-updater/updater.css'), $publishedPath);
        }

        return route('updater.asset.css', ['v' => self::versionFromPath(__DIR__ . '/../../resources/assets/updater.css')]);
    }

    public static function jsUrl(): string
    {
        $publishedPath = public_path('vendor/laravel-updater/updater.js');
        $strategy = (string) config('updater.ui.assets.strategy', 'route');

        if ($strategy === 'published' || ($strategy === 'auto' && is_file($publishedPath))) {
            return self::withVersion(asset('vendor/laravel-updater/updater.js'), $publishedPath);
        }

        return route('updater.asset.js', ['v' => self::versionFromPath(__DIR__ . '/../../resources/assets/updater.js')]);
    }

    public static function brandingLogoUrl(): string
    {
        return route('updater.branding.logo');
    }

    public static function faviconUrl(): string
    {
        return route('updater.branding.favicon');
    }

    private static function withVersion(string $url, string $path): string
    {
        return $url . '?v=' . self::versionFromPath($path);
    }

    private static function versionFromPath(string $path): string
    {
        $mtime = @filemtime($path);

        return $mtime !== false ? (string) $mtime : '1';
    }
}
