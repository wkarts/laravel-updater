<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\StateStore;
use PHPUnit\Framework\TestCase;

class StateStoreTest extends TestCase
{
    public function testEnsureSchemaECreateRun(): void
    {
        $path = sys_get_temp_dir() . '/updater_test_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $runId = $store->createRun(['seed' => true]);
        $this->assertGreaterThan(0, $runId);

        $last = $store->lastRun();
        $this->assertSame('running', $last['status']);
        @unlink($path);
    }
}
