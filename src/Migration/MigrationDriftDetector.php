<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Migration;

use Illuminate\Database\ConnectionResolverInterface;

class MigrationDriftDetector
{
    public function __construct(private readonly ConnectionResolverInterface $resolver)
    {
    }

    public function inspect(string $migrationPath, ?string $connection = null): array
    {
        $content = @file_get_contents($migrationPath) ?: '';
        $schema = $this->resolver->connection($connection)->getSchemaBuilder();

        if (preg_match("/Schema::create\(['\"]([a-zA-Z0-9_.$-]+)['\"]/", $content, $matches) === 1) {
            $table = $matches[1];
            if ($schema->hasTable($table)) {
                return ['action' => 'reconcile', 'reason' => 'table_exists', 'object' => ['type' => 'table', 'name' => $table]];
            }
        }

        if (preg_match("/Schema::table\(['\"]([a-zA-Z0-9_.$-]+)['\"]/,", $content, $matches) === 1) {
            $table = $matches[1];
            if (!$schema->hasTable($table)) {
                return ['action' => 'fail', 'reason' => 'target_table_missing', 'object' => ['type' => 'table', 'name' => $table]];
            }
        }

        return ['action' => 'run', 'reason' => 'no_obvious_drift', 'object' => null];
    }
}
