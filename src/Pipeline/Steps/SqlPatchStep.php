<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Support\Facades\DB;

class SqlPatchStep implements PipelineStepInterface
{
    public function __construct(private readonly string $defaultPatchPath, private readonly StateStore $store)
    {
    }

    public function name(): string { return 'sql_patch'; }

    public function shouldRun(array $context): bool
    {
        if (!(bool) config('updater.patches.enabled', true)) {
            return false;
        }

        $path = $this->resolvePath($context);
        return is_dir($path) || is_file($path);
    }

    public function handle(array &$context): void
    {
        $path = $this->resolvePath($context);
        $files = is_file($path) ? [$path] : (glob(rtrim($path, '/') . '/*.sql') ?: []);
        sort($files);

        foreach ($files as $file) {
            $sha = hash_file('sha256', $file);
            if ($sha === false || $this->store->patchAlreadyExecuted($sha)) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            DB::connection()->unprepared($sql);
            $this->store->registerPatch(basename($file), $sha, (int) ($context['run_id'] ?? 0), $context['revision_after'] ?? $context['revision_before'] ?? null);
        }
    }

    public function rollback(array &$context): void
    {
    }

    private function resolvePath(array $context): string
    {
        return (string) ($context['options']['sql_path'] ?? $this->defaultPatchPath);
    }
}
