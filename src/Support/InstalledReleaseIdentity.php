<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Symfony\Component\Process\Process;

/** Reads the installed application's identity locally; never infers it from an update request. */
class InstalledReleaseIdentity
{
    private const LIMIT = 20000;
    private const TARGET_PREFIX = 'refs/laravel-updater/targets/';

    public function __construct(private readonly string $directory)
    {
    }

    /** @return array{version:string,tag:?string,base_stable_tag:?string,git_commit_sha:string,release_channel:string,build_number:?string,source:string}|null */
    public function resolve(): ?array
    {
        $marker = rtrim($this->directory, '/\\') . '/.git';
        clearstatcache(true, $marker);
        $hasGit = is_dir($marker) || is_file($marker);
        $head = $hasGit ? $this->git(['rev-parse', '--verify', 'HEAD'], 128) : null;
        $head = $head !== null ? strtolower(trim($head)) : null;
        if ($hasGit && !$this->isSha($head)) {
            return null;
        }

        if ($head !== null && ($identity = $this->fromRefs(self::TARGET_PREFIX, $head)) !== null) {
            return $identity;
        }

        // Existing ZIP builders may supply any of these conventional metadata files.
        foreach (['release-info.json', 'build-info.json', 'ui-build-info.json', 'public/ui-build-info.json'] as $file) {
            $path = rtrim($this->directory, '/\\') . '/' . $file;
            clearstatcache(true, $path);
            if (!is_file($path) || is_link($path) || !is_readable($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size === false || $size < 2 || $size > self::LIMIT) {
                continue;
            }
            $contents = @file_get_contents($path, false, null, 0, self::LIMIT + 1);
            $metadata = is_string($contents) ? $this->json($contents) : null;
            if ($metadata !== null && ($identity = $this->metadata($metadata, $head, 'build-info')) !== null) {
                return $identity;
            }
        }

        return $head !== null ? $this->fromRefs('refs/tags/', $head) : null;
    }

    private function fromRefs(string $prefix, string $head): ?array
    {
        $output = $this->git([
            'for-each-ref', '--points-at=' . $head, '--count=128', '--sort=-version:refname',
            '--format=%(refname)%09%(objecttype)%09%(objectname)%09%(*objecttype)%09%(*objectname)', $prefix,
        ]);
        if ($output === null) {
            return null;
        }
        foreach (explode("\n", rtrim($output, "\r\n")) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) !== 5 || !str_starts_with($parts[0], $prefix)) {
                continue;
            }
            [$ref, $type, $object, $peeledType, $peeled] = $parts;
            $tag = substr($ref, strlen($prefix));
            $commit = $type === 'commit' ? $object : ($peeledType === 'commit' ? $peeled : null);
            if (!$this->isTag($tag) || $commit === null || strtolower($commit) !== $head) {
                continue;
            }

            $exactVersion = $this->version($tag);
            if ($type === 'tag' && $this->isSha($object)) {
                $contents = $this->git(['cat-file', 'tag', $object]);
                $separator = $contents !== null ? strpos($contents, "\n\n") : false;
                $metadata = $separator !== false ? $this->json(substr($contents, $separator + 2)) : null;
                if ($metadata !== null && ($metadata['schema_version'] ?? null) === 1) {
                    $identity = $this->metadata($metadata, $head, 'git-tag', $tag);
                    if ($identity !== null && ($exactVersion === null || $identity['version'] === $exactVersion)) {
                        return $identity;
                    }
                }
            }

            if ($exactVersion !== null) {
                return [
                    'version' => $exactVersion, 'tag' => $tag, 'base_stable_tag' => null,
                    'git_commit_sha' => $head, 'release_channel' => $this->isPrerelease($exactVersion) ? 'prerelease' : 'stable',
                    'build_number' => null, 'source' => 'git-tag',
                ];
            }
        }
        return null;
    }

    private function metadata(array $data, ?string $head, string $source, ?string $tag = null): ?array
    {
        if (array_key_exists('schema_version', $data) && $data['schema_version'] !== 1) {
            return null;
        }
        $version = is_string($data['version'] ?? null) ? $this->version($data['version']) : null;
        $sha = is_string($data['git_commit_sha'] ?? null) ? strtolower($data['git_commit_sha']) : null;
        if ($version === null || !$this->isSha($sha) || ($head !== null && $sha !== $head)) {
            return null;
        }
        $base = $data['base_stable_tag'] ?? null;
        if ($base !== null) {
            $base = is_string($base) ? $this->version($base) : null;
            if ($base === null || str_contains($base, '-') || str_contains($base, '+')) {
                return null;
            }
            $base = 'v' . $base;
        }
        $channel = $data['release_channel'] ?? ($this->isPrerelease($version) ? 'prerelease' : 'stable');
        if (!is_string($channel) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/D', $channel) !== 1
            || ($channel === 'stable' && $this->isPrerelease($version))) {
            return null;
        }
        $build = $data['build_number'] ?? null;
        if ($build !== null && !is_int($build) && !is_string($build)) {
            return null;
        }
        $build = $build !== null ? (string) $build : null;
        if ($build !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $build) !== 1) {
            return null;
        }
        if ($tag === null && isset($data['tag'])) {
            if (!is_string($data['tag']) || !$this->isTag($data['tag'])) {
                return null;
            }
            $tag = $data['tag'];
        }
        return [
            'version' => $version, 'tag' => $tag, 'base_stable_tag' => $base,
            'git_commit_sha' => $sha, 'release_channel' => $channel, 'build_number' => $build, 'source' => $source,
        ];
    }

    private function json(string $contents): ?array
    {
        if (strlen($contents) > self::LIMIT) {
            return null;
        }
        try {
            $data = json_decode(trim($contents), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) && !array_is_list($data) ? $data : null;
    }

    private function version(string $value): ?string
    {
        $value = preg_replace('/^[vV]/', '', trim($value));
        if (!is_string($value) || strlen($value) > 120) {
            return null;
        }
        $number = '(?:0|[1-9][0-9]*)';
        $pre = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
        return preg_match('/^' . $number . '\.' . $number . '\.' . $number
            . '(?:-' . $pre . '(?:\.' . $pre . ')*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $value) === 1 ? $value : null;
    }

    private function isPrerelease(string $version): bool
    {
        return str_contains(explode('+', $version, 2)[0], '-');
    }

    private function isSha(?string $value): bool
    {
        return $value !== null && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value) === 1;
    }

    private function isTag(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\/-]{0,254}$/D', $value) === 1 && !str_contains($value, '..');
    }

    /** Local, bounded, read-only commands; existing secrets and arbitrary metadata never reach the result. */
    private function git(array $arguments, int $limit = self::LIMIT): ?string
    {
        $process = new Process(array_merge(['git'], $arguments), $this->directory, [
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1', 'LC_ALL' => 'C',
        ]);
        $process->setTimeout(3);
        $output = '';
        $bytes = 0;
        try {
            $process->run(static function (string $type, string $buffer) use (&$output, &$bytes, $limit): void {
                $bytes += strlen($buffer);
                if ($bytes > $limit) {
                    throw new \OverflowException('Installed release metadata exceeds the local read limit.');
                }
                if ($type === Process::OUT) {
                    $output .= $buffer;
                }
            });
        } catch (\Throwable) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            return null;
        }
        return $process->isSuccessful() ? $output : null;
    }
}
