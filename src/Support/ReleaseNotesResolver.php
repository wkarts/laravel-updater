<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

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

        if (str_contains($normalized, 'github.com/')) {
            return $normalized . '/releases/tag/' . rawurlencode($tag);
        }

        if (str_contains($normalized, 'gitlab.com/')) {
            return $normalized . '/-/tags/' . rawurlencode($tag);
        }

        if (str_contains($normalized, 'bitbucket.org/')) {
            return $normalized . '/src/' . rawurlencode($tag);
        }

        return null;
    }

    private function normalizeRepositoryUrl(string $repoUrl): ?string
    {
        $repoUrl = preg_replace('/\.git$/', '', $repoUrl) ?? $repoUrl;

        if (preg_match('#^https?://#i', $repoUrl) === 1) {
            return rtrim($repoUrl, '/');
        }

        if (preg_match('/^git@([^:]+):(.+)$/', $repoUrl, $matches) === 1) {
            return sprintf('https://%s/%s', $matches[1], trim((string) $matches[2], '/'));
        }

        if (preg_match('/^ssh:\/\/git@([^\/]+)\/(.+)$/', $repoUrl, $matches) === 1) {
            return sprintf('https://%s/%s', $matches[1], trim((string) $matches[2], '/'));
        }

        return null;
    }
}

