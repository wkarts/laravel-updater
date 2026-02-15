<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Migration;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;

class MigrationReconciler
{
    public function __construct(private readonly ConnectionResolverInterface $resolver)
    {
    }

    public function reconcile(
        DatabaseMigrationRepository $repository,
        string $migrationName,
        array $object,
        array $errorDetails,
        ?string $connection = null,
        bool $strictMode = false
    ): array {
        if ($this->alreadyLogged($repository, $migrationName)) {
            return [
                'reconciled' => true,
                'reason' => 'already_logged',
                'warning' => false,
                'validation' => ['compatible' => true, 'note' => 'migration_already_in_repository'],
            ];
        }

        $validation = $this->validateMinimumCompatibility($object, $errorDetails, $connection, $strictMode);

        if (($validation['compatible'] ?? false) !== true) {
            return [
                'reconciled' => false,
                'reason' => 'minimum_compatibility_failed',
                'warning' => (bool) ($validation['warning'] ?? false),
                'validation' => $validation,
            ];
        }

        $repository->log($migrationName, $repository->getNextBatchNumber());

        return [
            'reconciled' => true,
            'reason' => 'already_exists_safe',
            'warning' => (bool) ($validation['warning'] ?? false),
            'validation' => $validation,
        ];
    }

    private function alreadyLogged(DatabaseMigrationRepository $repository, string $migrationName): bool
    {
        return in_array($migrationName, $repository->getRan(), true);
    }

    public function validateMinimumCompatibility(array $object, array $errorDetails, ?string $connection = null, bool $strictMode = false): array
    {
        $conn = $this->resolver->connection($connection);
        $schema = $conn->getSchemaBuilder();
        $type = (string) ($object['type'] ?? 'unknown');
        $name = is_string($object['name'] ?? null) ? $object['name'] : null;
        $table = is_string($object['table'] ?? null) ? $object['table'] : null;

        if ($type === 'table' && $name !== null) {
            return [
                'compatible' => $schema->hasTable($name),
                'warning' => false,
                'note' => 'validated_table_exists',
            ];
        }

        if ($type === 'view' && $name !== null) {
            return [
                'compatible' => $schema->hasTable($name),
                'warning' => false,
                'note' => 'validated_view_exists_via_schema',
            ];
        }


        if ($type === 'index' && $name !== null && $table !== null) {
            $exists = $this->indexExists($connection, $table, $name);

            return [
                'compatible' => !$exists,
                'warning' => false,
                'note' => 'validated_index_already_absent',
            ];
        }

        if ($type === 'constraint' && $name !== null) {
            $exists = $this->constraintExists($connection, $table, $name);

            return [
                'compatible' => $exists,
                'warning' => false,
                'note' => 'validated_constraint_exists_information_schema',
            ];
        }

        if ($strictMode) {
            return [
                'compatible' => false,
                'warning' => true,
                'note' => 'strict_mode_requires_safe_inference',
                'details' => $errorDetails,
            ];
        }

        return [
            'compatible' => true,
            'warning' => true,
            'note' => 'object_unknown_reconciled_in_tolerant_mode',
            'details' => $errorDetails,
        ];
    }

    private function constraintExists(?string $connection, ?string $table, string $constraint): bool
    {
        $conn = $this->resolver->connection($connection);
        $driver = $conn->getDriverName();
        $database = (string) $conn->getDatabaseName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $query = 'SELECT COUNT(*) AS total
                FROM information_schema.TABLE_CONSTRAINTS tc
                LEFT JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                    AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                    AND tc.TABLE_NAME = kcu.TABLE_NAME
                WHERE tc.CONSTRAINT_SCHEMA = ?
                    AND tc.CONSTRAINT_NAME = ?';
            $bindings = [$database, $constraint];

            if ($table !== null && $table !== '') {
                $query .= ' AND tc.TABLE_NAME = ?';
                $bindings[] = $table;
            }

            $row = $conn->selectOne($query, $bindings);

            return (int) ($row->total ?? 0) > 0;
        }

        if ($driver === 'pgsql') {
            $query = 'SELECT COUNT(*) AS total FROM information_schema.table_constraints WHERE constraint_name = ?';
            $bindings = [$constraint];

            if ($table !== null && $table !== '') {
                $query .= ' AND table_name = ?';
                $bindings[] = $table;
            }

            $row = $conn->selectOne($query, $bindings);

            return (int) ($row->total ?? 0) > 0;
        }

        return false;
    }

    private function indexExists(?string $connection, string $table, string $index): bool
    {
        $conn = $this->resolver->connection($connection);
        $driver = $conn->getDriverName();
        $database = (string) $conn->getDatabaseName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = $conn->selectOne(
                'SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$database, $table, $index]
            );

            return (int) ($row->total ?? 0) > 0;
        }

        if ($driver === 'pgsql') {
            $row = $conn->selectOne(
                'SELECT COUNT(*) AS total FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return (int) ($row->total ?? 0) > 0;
        }

        return false;
    }

}
