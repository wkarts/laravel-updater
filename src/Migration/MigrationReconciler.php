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
        ?string $connection = null,
        bool $strict = false
    ): array {
        if ($this->isMigrationLogged($repository, $migrationName)) {
            return [
                'reconciled' => true,
                'reason' => 'already_logged',
                'validation' => ['compatible' => true, 'note' => 'migration_already_registered'],
            ];
        }

        $validation = $this->validateMinimumCompatibility($object, $connection);

        if (($validation['compatible'] ?? false) !== true) {
            return [
                'reconciled' => false,
                'reason' => 'minimum_compatibility_failed',
                'validation' => $validation,
            ];
        }

        if ($strict && ($validation['warning'] ?? false) === true) {
            return [
                'reconciled' => false,
                'reason' => 'strict_mode_requires_confident_probe',
                'validation' => $validation,
            ];
        }

        $repository->log($migrationName, $repository->getNextBatchNumber());

        return [
            'reconciled' => true,
            'reason' => 'already_exists_safe',
            'validation' => $validation,
        ];
    }

    public function validateMinimumCompatibility(array $object, ?string $connection = null): array
    {
        $schema = $this->resolver->connection($connection)->getSchemaBuilder();
        $type = (string) ($object['type'] ?? 'unknown');
        $name = $object['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return ['compatible' => true, 'note' => 'object_name_unknown', 'warning' => true];
        }

        if ($type === 'table' || $type === 'view') {
            return [
                'compatible' => $schema->hasTable($name),
                'note' => 'validated_table_or_view_exists',
                'warning' => false,
            ];
        }

        if ($type === 'constraint') {
            return $this->validateConstraint($object, $connection);
        }

        return [
            'compatible' => true,
            'note' => 'no_safe_probe_for_type',
            'warning' => true,
        ];
    }

    private function validateConstraint(array $object, ?string $connection): array
    {
        $constraint = (string) ($object['name'] ?? '');
        $table = (string) ($object['table'] ?? '');

        if ($constraint === '') {
            return ['compatible' => false, 'note' => 'constraint_name_missing', 'warning' => true];
        }

        $db = $this->resolver->connection($connection);
        $database = (string) $db->getDatabaseName();

        $query = 'SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = ?';
        $bindings = [$database, $constraint];

        if ($table !== '') {
            $query .= ' AND TABLE_NAME = ?';
            $bindings[] = $table;
        }

        $rows = $db->select($query, $bindings);
        $count = (int) ((array) ($rows[0] ?? []))['total'];

        return [
            'compatible' => $count > 0,
            'note' => 'validated_constraint_exists',
            'warning' => $table === '',
        ];
    }

    private function isMigrationLogged(DatabaseMigrationRepository $repository, string $migrationName): bool
    {
        return in_array($migrationName, $repository->getRan(), true);
    }
}
