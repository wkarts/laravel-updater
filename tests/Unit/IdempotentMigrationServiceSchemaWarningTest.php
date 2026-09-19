<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Migration\IdempotentMigrationService;
use Argws\LaravelUpdater\Migration\MigrationDriftDetector;
use Argws\LaravelUpdater\Migration\MigrationFailureClassifier;
use Argws\LaravelUpdater\Migration\MigrationReconciler;
use Argws\LaravelUpdater\Migration\MigrationRunReporter;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

class IdempotentMigrationServiceSchemaWarningTest extends TestCase
{
    public function testModoToleranteMantemMigrationCom1067PendenteEContinuaAsProximas(): void
    {
        $repository = $this->getMockBuilder(DatabaseMigrationRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['repositoryExists', 'createRepository', 'getRan', 'log', 'getNextBatchNumber'])
            ->getMock();

        $repository->method('repositoryExists')->willReturn(true);
        $repository->method('getRan')->willReturn([]);
        $repository->expects($this->never())->method('log');

        $migrator = $this->getMockBuilder(Migrator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRepository', 'setConnection', 'getMigrationFiles', 'run'])
            ->getMock();

        $migrator->method('getRepository')->willReturn($repository);
        $migrator->method('getMigrationFiles')->willReturn([
            '2026_07_26_120023_update_bank_statement_transactions_table' => '/tmp/2026_07_26_120023_update_bank_statement_transactions_table.php',
            '2026_07_26_201008_update_bank_statement_transactions_table' => '/tmp/2026_07_26_201008_update_bank_statement_transactions_table.php',
        ]);

        $executedPaths = [];
        $migrator->method('run')->willReturnCallback(
            function (array $paths) use (&$executedPaths): array {
                $path = (string) ($paths[0] ?? '');
                $executedPaths[] = $path;

                if (str_contains($path, '120023')) {
                    throw new \RuntimeException(
                        "SQLSTATE[42000]: Syntax error or access violation: 1067 Invalid default value for 'status' "
                        . "(Connection: mysql, SQL: ALTER TABLE `bank_statement_transactions` MODIFY COLUMN `status` "
                        . "enum('pending','reconciled','ignored') NULL DEFAULT 'pendente')"
                    );
                }

                return [];
            }
        );

        $reconciler = $this->createMock(MigrationReconciler::class);
        $reconciler->expects($this->never())->method('reconcile');

        $driftDetector = $this->createMock(MigrationDriftDetector::class);
        $driftDetector->method('inspect')->willReturn([
            'action' => 'run',
            'reason' => 'default',
            'object' => null,
        ]);

        $service = new IdempotentMigrationService(
            $migrator,
            new MigrationFailureClassifier(),
            $reconciler,
            $driftDetector
        );

        $reporter = new MigrationRunReporter(
            null,
            sys_get_temp_dir() . '/updater-migrate-schema-warning-' . uniqid('', true) . '.log'
        );

        $stats = $service->run([
            'idempotent' => true,
            'database' => 'mysql',
            'mode' => 'tolerant',
            'strict' => false,
            'dry_run' => false,
            'replay_from_start' => false,
            'retry_locks' => 0,
            'retry_sleep_base' => 1,
        ], $reporter);

        $this->assertCount(2, $executedPaths);
        $this->assertSame(1, $stats['executed']);
        $this->assertSame(1, $stats['warnings']);
        $this->assertSame(1, $stats['skipped_schema_warning']);
        $this->assertSame(0, $stats['failed']);

        $schemaWarnings = array_values(array_filter(
            $stats['divergences'],
            static fn (array $item): bool => ($item['type'] ?? null) === 'SCHEMA_COMPATIBILITY_WARNING'
        ));
        $this->assertCount(1, $schemaWarnings);
        $this->assertSame('status', $schemaWarnings[0]['object']['name'] ?? null);
    }

    public function testModoStrictContinuaAbortandoEm1067(): void
    {
        $repository = $this->getMockBuilder(DatabaseMigrationRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['repositoryExists', 'createRepository', 'getRan'])
            ->getMock();

        $repository->method('repositoryExists')->willReturn(true);
        $repository->method('getRan')->willReturn([]);

        $migrator = $this->getMockBuilder(Migrator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRepository', 'setConnection', 'getMigrationFiles', 'run'])
            ->getMock();

        $migrator->method('getRepository')->willReturn($repository);
        $migrator->method('getMigrationFiles')->willReturn([
            '2026_07_26_120023_update_bank_statement_transactions_table' => '/tmp/2026_07_26_120023_update_bank_statement_transactions_table.php',
        ]);
        $migrator->method('run')->willThrowException(new \RuntimeException(
            "SQLSTATE[42000]: Syntax error or access violation: 1067 Invalid default value for 'status' "
            . "(Connection: mysql, SQL: ALTER TABLE `bank_statement_transactions` MODIFY COLUMN `status` "
            . "enum('pending','reconciled','ignored') NULL DEFAULT 'pendente')"
        ));

        $driftDetector = $this->createMock(MigrationDriftDetector::class);
        $driftDetector->method('inspect')->willReturn([
            'action' => 'run',
            'reason' => 'default',
            'object' => null,
        ]);

        $service = new IdempotentMigrationService(
            $migrator,
            new MigrationFailureClassifier(),
            $this->createMock(MigrationReconciler::class),
            $driftDetector
        );

        $reporter = new MigrationRunReporter(
            null,
            sys_get_temp_dir() . '/updater-migrate-schema-strict-' . uniqid('', true) . '.log'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid default value for 'status'");

        $service->run([
            'idempotent' => true,
            'database' => 'mysql',
            'mode' => 'strict',
            'strict' => true,
            'dry_run' => false,
            'replay_from_start' => false,
            'retry_locks' => 0,
            'retry_sleep_base' => 1,
        ], $reporter);
    }
}
