<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReleaseNotesResolver
{
    public function resolve(?string $repoUrl, ?string $tag): ?string
    {
        $repoUrl = trim((string) $repoUrl);
        $tag = trim((string) $tag);
        if ($repoUrl === '' || $tag === '') {
            return null;
        }

        $normalized = $this->normalizeRepositoryUrl($repoUrl);
        if ($normalized === null) {
            return null;
        }

        if (parse_url($normalized, PHP_URL_HOST) === 'github.com') {
            return $normalized . '/releases/tag/' . rawurlencode($tag);
        }

        if (parse_url($normalized, PHP_URL_HOST) === 'gitlab.com') {
            return $normalized . '/-/tags/' . rawurlencode($tag);
        }

        if (parse_url($normalized, PHP_URL_HOST) === 'bitbucket.org') {
            return $normalized . '/src/' . rawurlencode($tag);
        }

        return null;
    }

    /** Exact tag lookup. Never falls back to the latest release. Markdown is returned as text. */
    public function metadata(?string $repoUrl, ?string $tag, ?string $accessToken = null): array
    {
        $tag = trim((string) $tag);
        $normalized = $this->normalizeRepositoryUrl(trim((string) $repoUrl));
        $host = $normalized === null ? '' : (string) parse_url($normalized, PHP_URL_HOST);
        $result = [
            'tag' => $tag, 'name' => $tag, 'published_at' => null,
            'prerelease' => preg_match('/(?:^|[.\-])(?:alpha|beta|rc|preview|dev)(?:[.\-\d]|$)/i', $tag) === 1,
            'notes' => null, 'url' => $this->resolve($repoUrl, $tag),
            'provider' => $host === 'github.com' ? 'github' : ($host === 'gitlab.com' ? 'gitlab' : 'git'),
            'availability' => 'unavailable',
        ];
        if ($tag === '' || strlen($tag) > 120 || $normalized === null || !in_array($host, ['github.com', 'gitlab.com'], true)) {
            return $result;
        }

        // Credentials affect visibility but are never part of cache values or URLs.
        $cacheKey = 'updater:release:' . hash('sha256', $normalized . "\0" . $tag . "\0" . (string) $accessToken);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $project = trim((string) parse_url($normalized, PHP_URL_PATH), '/');
        if ($project === '' || str_contains($project, '..')) {
            return $result;
        }
        $endpoint = $host === 'github.com'
            ? 'https://api.github.com/repos/' . implode('/', array_map('rawurlencode', explode('/', $project))) . '/releases/tags/' . rawurlencode($tag)
            : 'https://gitlab.com/api/v4/projects/' . rawurlencode($project) . '/releases/' . rawurlencode($tag);
        try {
            $http = Http::acceptJson()->timeout(8)->connectTimeout(3)->withoutRedirecting();
            if ($accessToken !== null && $accessToken !== '') {
                $http = $host === 'gitlab.com' ? $http->withHeaders(['PRIVATE-TOKEN' => $accessToken]) : $http->withToken($accessToken);
            }
            $response = $http->get($endpoint);
            if ($response->status() === 429 || $response->serverError()) {
                $result['availability'] = 'temporarily_unavailable';
            }
            $release = $response->successful() ? $response->json() : null;
            if (is_array($release) && ($release['tag_name'] ?? null) === $tag && !($release['draft'] ?? false)) {
                $notes = $host === 'github.com' ? ($release['body'] ?? null) : ($release['description'] ?? null);
                $result['name'] = (string) ($release['name'] ?? $tag);
                $result['published_at'] = $release['published_at'] ?? $release['released_at'] ?? null;
                $result['prerelease'] = $host === 'github.com' ? (bool) ($release['prerelease'] ?? false) : $result['prerelease'];
                $result['notes'] = is_string($notes) && trim($notes) !== '' ? mb_substr($notes, 0, 100000) : null;
                $result['availability'] = $result['notes'] === null ? 'unavailable' : 'available';
                // Constructed provider link prevents untrusted release metadata from supplying script URLs.
            }
        } catch (Throwable) {
            $result['availability'] = 'temporarily_unavailable';
        }
        Cache::put($cacheKey, $result, $result['availability'] === 'available' ? 300 : 60);

        return $result;
    }

    private function normalizeRepositoryUrl(string $repoUrl): ?string
    {
        $repoUrl = rtrim(trim($repoUrl), '/');
        $repoUrl = preg_replace('/\.git$/', '', $repoUrl) ?? $repoUrl;

        if (preg_match('#^https?://#i', $repoUrl) === 1) {
            $parts = parse_url($repoUrl);
            if (!is_array($parts) || !isset($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
                return null;
            }
            return 'https://' . strtolower((string) $parts['host']) . rtrim((string) ($parts['path'] ?? ''), '/');
        }

        if (preg_match('/^git@([^:]+):(.+)$/', $repoUrl, $matches) === 1) {
            return sprintf('https://%s/%s', strtolower($matches[1]), trim((string) $matches[2], '/'));
        }

        if (preg_match('/^ssh:\/\/git@([^\/]+)\/(.+)$/', $repoUrl, $matches) === 1) {
            return sprintf('https://%s/%s', strtolower($matches[1]), trim((string) $matches[2], '/'));
        }

        return null;
    }
}

