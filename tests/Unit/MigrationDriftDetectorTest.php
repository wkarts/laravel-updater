<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Migration\MigrationDriftDetector;
use Illuminate\Database\ConnectionResolverInterface;
use PHPUnit\Framework\TestCase;

final class MigrationDriftDetectorTest extends TestCase
{
    public function testDetectaReconcileQuandoSchemaCreateJaExiste(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mig_');
        file_put_contents($file, "<?php\nSchema::create('usuarios', function () {});\n");

        $detector = new MigrationDriftDetector($this->resolverForTables(['usuarios']));
        $result = $detector->inspect($file ?: '');

        $this->assertSame('reconcile', $result['action']);
        $this->assertSame('usuarios', $result['object']['name']);
    }

    public function testDetectaFalhaQuandoSchemaTableNaoExiste(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mig_');
        file_put_contents($file, "<?php\nSchema::table('pedidos', function () {});\n");

        $detector = new MigrationDriftDetector($this->resolverForTables([]));
        $result = $detector->inspect($file ?: '');

        $this->assertSame('fail', $result['action']);
        $this->assertSame('pedidos', $result['object']['name']);
    }

    private function resolverForTables(array $existingTables): ConnectionResolverInterface
    {
        $schema = new class($existingTables) {
            public function __construct(private readonly array $existingTables)
            {
            }

            public function hasTable(string $table): bool
            {
                return in_array($table, $this->existingTables, true);
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
