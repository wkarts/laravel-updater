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

        $matches = [];
        if ($this->safeMatch('/Schema::create\([\'\"]([a-zA-Z0-9_.$-]+)[\'\"]/', $content, $matches)) {
            $table = $matches[1] ?? null;
            if (is_string($table) && $table !== '' && $schema->hasTable($table)) {
                return ['action' => 'reconcile', 'reason' => 'table_exists', 'object' => ['type' => 'table', 'name' => $table]];
            }
        }

        $matches = [];
        if ($this->safeMatch('/Schema::table\([\'\"]([a-zA-Z0-9_.$-]+)[\'\"],/', $content, $matches)) {
            $table = $matches[1] ?? null;
            if (is_string($table) && $table !== '' && !$schema->hasTable($table)) {
                return ['action' => 'fail', 'reason' => 'target_table_missing', 'object' => ['type' => 'table', 'name' => $table]];
            }
        }

        return ['action' => 'run', 'reason' => 'no_obvious_drift', 'object' => null];
    }

    /**
     * @param array<int,string> $matches
     */
    private function safeMatch(string $pattern, string $subject, array &$matches): bool
    {
        $result = @preg_match($pattern, $subject, $matches);

        return $result === 1;
    }
}
