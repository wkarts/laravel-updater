<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Illuminate\Support\Facades\DB;

class SqlPatchStep implements PipelineStepInterface
{
    public function __construct(private readonly string $patchPath)
    {
    }

    public function name(): string { return 'sql_patch'; }
    public function shouldRun(array $context): bool { return is_dir($this->patchPath); }

    public function handle(array &$context): void
    {
        $executed = $context['sql_patches'] ?? [];
        foreach (glob($this->patchPath . '/*.sql') ?: [] as $file) {
            if (in_array($file, $executed, true)) {
                continue;
            }
            DB::unprepared((string) file_get_contents($file));
            $executed[] = $file;
        }
        $context['sql_patches'] = $executed;
    }

    public function rollback(array &$context): void
    {
    }
}
