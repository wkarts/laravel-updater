<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Migration\MigrationReconciler;
use Illuminate\Database\ConnectionResolverInterface;
use PHPUnit\Framework\TestCase;

final class MigrationReconcilerTest extends TestCase
{
    public function testNaoReconciliarObjetoDesconhecidoMesmoEmModoTolerante(): void
    {
        $reconciler = new MigrationReconciler($this->resolverMock());

        $result = $reconciler->validateMinimumCompatibility([
            'type' => 'unknown',
            'name' => null,
            'table' => null,
            'expects_absent' => false,
        ], [
            'sqlstate' => null,
            'errno' => null,
            'message' => 'erro generico',
        ], null, false);

        $this->assertFalse($result['compatible']);
        $this->assertSame('object_unknown_requires_manual_review', $result['note']);
    }

    public function testReconciliarColunaQuandoJaExisteNaTabela(): void
    {
        $reconciler = new MigrationReconciler($this->resolverMock(['veiculos' => ['filial_id', 'deleted_at']]));

        $result = $reconciler->validateMinimumCompatibility([
            'type' => 'column',
            'name' => 'filial_id',
            'table' => 'veiculos',
            'expects_absent' => false,
        ], [
            'sqlstate' => '42S21',
            'errno' => 1060,
            'message' => "duplicate column name 'filial_id'",
        ], null, false);

        $this->assertTrue($result['compatible']);
        $this->assertSame('validated_column_exists', $result['note']);
    }

    private function resolverMock(array $columnsByTable = []): ConnectionResolverInterface
    {
        $schema = new class($columnsByTable) {
            public function __construct(private readonly array $columnsByTable)
            {
            }

            public function hasTable(string $table): bool
            {
                return false;
            }

            public function hasColumn(string $table, string $column): bool
            {
                $columns = $this->columnsByTable[$table] ?? [];

                return in_array($column, $columns, true);
            }
        };

        $connection = new class($schema) {
            public function __construct(private readonly object $schema)
            {
            }

            public function getSchemaBuilder(): object
            {
                return $this->schema;
            }

            public function getDriverName(): string
            {
                return 'mysql';
            }

            public function getDatabaseName(): string
            {
                return 'testing';
            }

            public function selectOne(string $query, array $bindings = []): object
            {
                return (object) ['total' => 0];
            }
        };

        return new class($connection) implements ConnectionResolverInterface {
            public function __construct(private readonly object $connection)
            {
            }

            public function connection($name = null)
            {
                return $this->connection;
            }

            public function getDefaultConnection()
            {
                return 'testing';
            }

            public function setDefaultConnection($name)
            {
            }
        };
    }
}
