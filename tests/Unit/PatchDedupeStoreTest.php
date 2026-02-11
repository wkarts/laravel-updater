<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\StateStore;
use PHPUnit\Framework\TestCase;

class PatchDedupeStoreTest extends TestCase
{
    public function testPatchDedupePorSha(): void
    {
        $path = sys_get_temp_dir() . '/updater_patch_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $runId = $store->createRun([]);
        $sha = hash('sha256', 'abc');
        $store->registerPatch('001_test.sql', $sha, $runId, 'rev1');
        $this->assertTrue($store->patchAlreadyExecuted($sha));

        @unlink($path);
    }
}
