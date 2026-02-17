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

    public function testEnsureSchemaCriaColunaSnapshotIncludeVendorNoPerfil(): void
    {
        $path = sys_get_temp_dir() . '/updater_test_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $columns = $store->pdo()->query("PRAGMA table_info('updater_profiles')")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $names = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);

        $this->assertContains('snapshot_include_vendor', $names);
        @unlink($path);
    }


    public function testEnsureSchemaCriaColunasDeUploadNuvemEmBackups(): void
    {
        $path = sys_get_temp_dir() . '/updater_test_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $columns = $store->pdo()->query("PRAGMA table_info('updater_backups')")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $names = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);

        $this->assertContains('cloud_uploaded', $names);
        $this->assertContains('cloud_provider', $names);
        $this->assertContains('cloud_upload_count', $names);
        @unlink($path);
    }

}
