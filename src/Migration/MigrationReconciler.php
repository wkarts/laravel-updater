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
        ?string $connection = null
    ): array {
        $validation = $this->validateMinimumCompatibility($object, $connection);

        if ($validation['compatible'] === false) {
            return [
                'reconciled' => false,
                'reason' => 'minimum_compatibility_failed',
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
            return ['compatible' => true, 'note' => 'object_name_unknown'];
        }

        return match ($type) {
            'table', 'view' => [
                'compatible' => $schema->hasTable($name),
                'note' => 'validated_table_or_view_exists',
            ],
            default => [
                'compatible' => true,
                'note' => 'no_safe_probe_for_type',
            ],
        };
    }
}
