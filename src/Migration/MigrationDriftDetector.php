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

        $createTable = $this->extractSchemaTableName($content, 'create');
        if (is_string($createTable) && $createTable !== '' && $schema->hasTable($createTable)) {
            return ['action' => 'reconcile', 'reason' => 'table_exists', 'object' => ['type' => 'table', 'name' => $createTable]];
        }

        $alterTable = $this->extractSchemaTableName($content, 'table');
        if (is_string($alterTable) && $alterTable !== '' && !$schema->hasTable($alterTable)) {
            return ['action' => 'fail', 'reason' => 'target_table_missing', 'object' => ['type' => 'table', 'name' => $alterTable]];
        }

        return ['action' => 'run', 'reason' => 'no_obvious_drift', 'object' => null];
    }

    private function extractSchemaTableName(string $content, string $method): ?string
    {
        $needle = 'Schema::' . $method . '(';
        $start = strpos($content, $needle);
        if ($start === false) {
            return null;
        }

        $cursor = $start + strlen($needle);
        while ($cursor < strlen($content) && ctype_space($content[$cursor])) {
            $cursor++;
        }

        if ($cursor >= strlen($content)) {
            return null;
        }

        $quote = $content[$cursor];
        if ($quote !== '\'' && $quote !== '"') {
            return null;
        }

        $cursor++;
        $end = strpos($content, $quote, $cursor);
        if ($end === false || $end <= $cursor) {
            return null;
        }

        $table = substr($content, $cursor, $end - $cursor);
        $table = trim($table);

        if ($table === '') {
            return null;
        }

        return $table;
    }
}
