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
        $this->assertContains('snapshot_compression', $names);
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

    public function testQueuedRunPodeSerIniciadoComPidEHeartbeat(): void
    {
        $path = sys_get_temp_dir() . '/updater_test_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $runId = $store->createQueuedRun(['update_type' => 'git_ff_only']);
        $queued = $store->findRun($runId);

        $this->assertSame('queued', $queued['status']);
        $this->assertNotEmpty($queued['heartbeat_at']);
        $this->assertTrue($store->hasActiveRun());

        $store->startRun($runId, 12345, 'cli-test');
        $running = $store->findRun($runId);

        $this->assertSame('running', $running['status']);
        $this->assertSame(12345, (int) $running['worker_pid']);
        $this->assertSame('cli-test', $running['execution_mode']);

        $store->updateRunStatus($runId, 'failed', ['message' => 'teste']);
        $this->assertFalse($store->hasActiveRun());

        @unlink($path);
    }

    public function testEnsureSchemaCriaColunasDeRecuperacaoNasRuns(): void
    {
        $path = sys_get_temp_dir() . '/updater_test_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $columns = $store->pdo()->query("PRAGMA table_info('runs')")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $names = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);

        $this->assertContains('heartbeat_at', $names);
        $this->assertContains('worker_pid', $names);
        $this->assertContains('execution_mode', $names);
        $this->assertContains('current_step', $names);

        @unlink($path);
    }

    public function testRunMantemEtapaAtualParaDiagnosticoERecovery(): void
    {
        $path = sys_get_temp_dir() . '/updater_test_' . uniqid() . '.sqlite';
        $store = new StateStore($path);
        $store->ensureSchema();

        $runId = $store->createRun(['update_type' => 'git_ff_only']);
        $store->setRunStep($runId, 'git_update');

        $running = $store->findRun($runId);
        $this->assertSame('git_update', $running['current_step']);
        $this->assertNotEmpty($running['heartbeat_at']);

        $store->finishRun($runId, []);
        $finished = $store->findRun($runId);
        $this->assertNull($finished['current_step']);

        @unlink($path);
    }

}
