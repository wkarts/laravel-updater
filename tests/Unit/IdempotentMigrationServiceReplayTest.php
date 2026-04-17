<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Migration\IdempotentMigrationService;
use Argws\LaravelUpdater\Migration\MigrationDriftDetector;
use Argws\LaravelUpdater\Migration\MigrationFailureClassifier;
use Argws\LaravelUpdater\Migration\MigrationReconciler;
use Argws\LaravelUpdater\Migration\MigrationRunReporter;
use Illuminate\Database\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

class IdempotentMigrationServiceReplayTest extends TestCase
{
    public function testReplayFromStartReconciliaAlreadyExistsMesmoComFlagDesligada(): void
    {
        $repository = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['repositoryExists', 'createRepository', 'getRan', 'delete', 'log', 'getNextBatchNumber'])
            ->getMock();

        $repository->method('repositoryExists')->willReturn(true);
        $repository->method('getRan')->willReturn(['2024_01_01_000000_create_acesso_logs_table']);
        $repository->expects($this->once())
            ->method('delete')
            ->with((object) ['migration' => '2024_01_01_000000_create_acesso_logs_table']);
        $repository->method('getNextBatchNumber')->willReturn(99);

        $migrator = $this->getMockBuilder(Migrator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRepository', 'setConnection', 'getMigrationFiles', 'run'])
            ->getMock();

        $migrator->method('getRepository')->willReturn($repository);
        $migrator->method('getMigrationFiles')->willReturn([
            '2024_01_01_000000_create_acesso_logs_table' => '/tmp/2024_01_01_000000_create_acesso_logs_table.php',
        ]);
        $migrator->method('run')->willThrowException(new \RuntimeException(
            "SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'acesso_logs' already exists"
        ));

        $classifier = $this->createMock(MigrationFailureClassifier::class);
        $classifier->method('classify')->willReturn(MigrationFailureClassifier::ALREADY_EXISTS);
        $classifier->method('inferObject')->willReturn([
            'type' => 'table',
            'name' => 'acesso_logs',
            'table' => 'acesso_logs',
            'expects_absent' => false,
        ]);
        $classifier->method('extractErrorDetails')->willReturn([
            'sqlstate' => '42S01',
            'errno' => 1050,
            'message' => "base table or view already exists",
        ]);

        $reconciler = $this->createMock(MigrationReconciler::class);
        $reconciler->expects($this->once())
            ->method('reconcile')
            ->willReturn(['reconciled' => true, 'warning' => false]);

        $driftDetector = $this->createMock(MigrationDriftDetector::class);
        $driftDetector->method('inspect')->willReturn([
            'action' => 'run',
            'reason' => 'default',
            'object' => null,
        ]);

        $service = new IdempotentMigrationService($migrator, $classifier, $reconciler, $driftDetector);
        $reporter = new MigrationRunReporter(null, sys_get_temp_dir() . '/updater-migrate-test-' . uniqid('', true) . '.log');

        $stats = $service->run([
            'idempotent' => true,
            'database' => 'mysql',
            'mode' => 'tolerant',
            'strict' => false,
            'dry_run' => false,
            'replay_from_start' => true,
            'reconcile_already_exists' => false,
            'retry_locks' => 0,
            'retry_sleep_base' => 1,
        ], $reporter);

        $this->assertSame(1, $stats['reconciled']);
        $this->assertSame(0, $stats['failed']);
    }
}

