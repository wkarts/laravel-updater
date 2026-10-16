<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class SpaAssets
{
    public static function directory(): string { return __DIR__ . '/../../resources/dist'; }

    /** Hashed files are served directly from Composer package, without writable public/ or publish. */
    public function serve(Request $request, string $file): Response
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_\-\/.]+\.(?:js|css|woff2?|svg|png|ico)\z/D', $file) === 1, 404);
        $root = realpath(self::directory());
        $path = $root === false ? false : realpath($root . '/' . $file);
        abort_unless($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path), 404);
        $types = ['js' => 'application/javascript', 'css' => 'text/css', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon'];
        return response()->file($path, [
            'Content-Type' => $types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function manifestEntry(): array
    {
        foreach (['/.vite/manifest.json', '/manifest.json'] as $suffix) {
            if (!is_file(self::directory() . $suffix)) { continue; }
            $manifest = json_decode((string) file_get_contents(self::directory() . $suffix), true) ?: [];
            $styles = [];
            foreach ($manifest as $asset) {
                $styles = array_merge($styles, (array) ($asset['css'] ?? []));
                if (str_ends_with((string) ($asset['file'] ?? ''), '.css')) { $styles[] = $asset['file']; }
            }
            foreach ($manifest as $item) {
                if (!empty($item['isEntry']) && str_ends_with((string) ($item['file'] ?? ''), '.js')) {
                    $item['css'] = array_values(array_unique(array_merge((array) ($item['css'] ?? []), $styles)));
                    return $item;
                }
            }
        }
        return [];
    }

    /** Cached route maps from older releases retain their original public asset endpoints. */
    public function legacy(string $type): Response
    {
        $entry = $this->manifestEntry();
        $file = $type === 'js' ? ($entry['file'] ?? '') : ($entry['css'][0] ?? '');
        abort_if($file === '', 404);
        return $this->serve(request(), $file);
    }

    public function entry(): array
    {
        $item = $this->manifestEntry();
        if ($item === []) { return ['js' => null, 'css' => []]; }
        $assetUrl = function (string $file, string $type): string {
            if (Route::has('updater.spa.asset')) { return route('updater.spa.asset', ['file' => $file]); }
            $legacy = $type === 'js' ? 'updater.asset.js' : 'updater.asset.css';
            $version = is_file(self::directory() . '/' . $file) ? hash_file('sha256', self::directory() . '/' . $file) : basename($file);
            return route($legacy, ['spa' => '1', 'v' => $version]);
        };
        return ['js' => $assetUrl($item['file'], 'js'), 'css' => array_map(fn ($file) => $assetUrl($file, 'css'), $item['css'] ?? [])];
    }
}
