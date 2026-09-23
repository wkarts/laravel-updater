<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Migration;

use Illuminate\Database\DatabaseManager;
use Throwable;

final class SchemaCompatibilityResolver
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $journalPath
    ) {
    }

    public function recoverIncompleteRepairs(?string $connection = null): array
    {
        if (!is_dir($this->journalPath)) {
            return ['checked' => 0, 'recovered' => 0];
        }

        $checked = 0;
        $recovered = 0;

        foreach ((array) glob(rtrim($this->journalPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $payload = json_decode((string) @file_get_contents($file), true);
            if (!is_array($payload) || ($payload['status'] ?? null) === 'complete') {
                continue;
            }

            if (($payload['connection'] ?? null) !== $connection) {
                continue;
            }

            $checked++;
            $table = (string) ($payload['table'] ?? '');
            $constraints = (array) ($payload['constraints'] ?? []);

            if (!$this->validIdentifier($table)) {
                continue;
            }

            $conn = $this->db->connection($connection ?: null);

            foreach ($constraints as $constraint) {
                if (!is_array($constraint)) {
                    continue;
                }

                $name = (string) ($constraint['name'] ?? '');
                $definition = (string) ($constraint['definition'] ?? '');

                if (!$this->validIdentifier($name) || $definition === '') {
                    continue;
                }

                $ddl = $this->showCreateTable($table, $connection);
                if ($this->hasConstraint($ddl, $name)) {
                    continue;
                }

                $conn->statement(
                    'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD ' . $definition
                );
            }

            $payload['status'] = 'complete';
            $payload['phase'] = 'recovered';
            $payload['recovered_at'] = date(DATE_ATOM);
            @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $recovered++;
        }

        return ['checked' => $checked, 'recovered' => $recovered];
    }

    public function repair(Throwable $throwable, ?string $connection = null): array
    {
        $context = $this->inferContext($throwable->getMessage());

        if ($context === null) {
            return ['repaired' => false, 'reason' => 'unsupported_error'];
        }

        [$table, $column, $targetNullable, $source] = $context;

        if (!$targetNullable) {
            return ['repaired' => false, 'reason' => 'unsafe_target'];
        }

        $ddl = $this->showCreateTable($table, $connection);
        $originalDefinition = $this->extractColumnDefinition($ddl, $column);

        if ($originalDefinition === null) {
            return ['repaired' => false, 'reason' => 'column_not_found'];
        }

        if (preg_match('/\bGENERATED\b/i', $originalDefinition) === 1) {
            return ['repaired' => false, 'reason' => 'generated_column'];
        }

        $alreadyNullable = preg_match('/\bNOT\s+NULL\b/i', $originalDefinition) !== 1;
        $targetDefinition = $alreadyNullable
            ? $originalDefinition
            : preg_replace('/\bNOT\s+NULL\b/i', 'NULL', $originalDefinition, 1);

        if (!is_string($targetDefinition) || trim($targetDefinition) === '') {
            return ['repaired' => false, 'reason' => 'definition_transform_failed'];
        }

        $constraints = $this->extractForeignKeyDefinitions($ddl, $column);
        $journal = $this->openJournal(
            $connection,
            $table,
            $column,
            $originalDefinition,
            $targetDefinition,
            $constraints,
            $source
        );

        $conn = $this->db->connection($connection ?: null);

        try {
            foreach ($constraints as $constraint) {
                $conn->statement(
                    'ALTER TABLE ' . $this->quoteIdentifier($table)
                    . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($constraint['name'])
                );
            }

            $this->updateJournal($journal, 'foreign_keys_dropped');

            if (!$alreadyNullable) {
                $conn->statement(
                    'ALTER TABLE ' . $this->quoteIdentifier($table)
                    . ' MODIFY COLUMN ' . $targetDefinition
                );
            }

            $this->updateJournal($journal, 'column_modified');

            foreach ($constraints as $constraint) {
                $conn->statement(
                    'ALTER TABLE ' . $this->quoteIdentifier($table)
                    . ' ADD ' . $constraint['definition']
                );
            }

            $this->updateJournal($journal, 'foreign_keys_restored');

            $verifiedDdl = $this->showCreateTable($table, $connection);
            $verifiedColumn = $this->extractColumnDefinition($verifiedDdl, $column);

            if ($verifiedColumn === null || preg_match('/\bNOT\s+NULL\b/i', $verifiedColumn) === 1) {
                throw new \RuntimeException('Schema compatibility repair could not verify nullable target.');
            }

            foreach ($constraints as $constraint) {
                if (!$this->hasConstraint($verifiedDdl, $constraint['name'])) {
                    throw new \RuntimeException(
                        'Schema compatibility repair could not verify restored foreign key: ' . $constraint['name']
                    );
                }
            }

            $this->updateJournal($journal, 'complete', true);

            return [
                'repaired' => true,
                'table' => $table,
                'column' => $column,
                'source' => $source,
                'original_definition' => $originalDefinition,
                'target_definition' => $targetDefinition,
                'foreign_keys' => array_column($constraints, 'name'),
                'already_nullable' => $alreadyNullable,
                'journal' => $journal,
            ];
        } catch (Throwable $repairError) {
            try {
                $currentDdl = $this->showCreateTable($table, $connection);

                foreach ($constraints as $constraint) {
                    if ($this->hasConstraint($currentDdl, $constraint['name'])) {
                        continue;
                    }

                    $conn->statement(
                        'ALTER TABLE ' . $this->quoteIdentifier($table)
                        . ' ADD ' . $constraint['definition']
                    );

                    $currentDdl = $this->showCreateTable($table, $connection);
                }
            } catch (Throwable) {
                // Journal permanece pendente e será recuperado na próxima execução.
            }

            $this->updateJournal($journal, 'failed', false, $repairError->getMessage());

            return [
                'repaired' => false,
                'reason' => 'repair_failed',
                'error' => $repairError->getMessage(),
                'journal' => $journal,
            ];
        }
    }

    private function inferContext(string $message): ?array
    {
        $table = null;
        $column = null;
        $targetNullable = false;
        $source = 'sql_error';

        if (
            preg_match(
                '/ALTER\s+TABLE\s+["\x60]?([a-zA-Z0-9_$]+)["\x60]?.*?MODIFY(?:\s+COLUMN)?\s+["\x60]?([a-zA-Z0-9_$]+)["\x60]?\s+(.+?)(?:\)|$)/is',
                $message,
                $matches
            ) === 1
        ) {
            $table = $matches[1];
            $column = $matches[2];
            $definition = $matches[3];
            $targetNullable = preg_match('/\bNULL\b/i', $definition) === 1
                && preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1;
        }

        if (
            ($table === null || $column === null)
            && preg_match(
                '/Altera(?:ç|c)ão depende de FK ativa[^:]*:\s*([a-zA-Z0-9_$]+)\.([a-zA-Z0-9_$]+)/iu',
                $message,
                $matches
            ) === 1
        ) {
            $table = $matches[1];
            $column = $matches[2];
            $targetNullable = true;
            $source = 'active_fk_guard';
        }

        if (
            $table === null
            || $column === null
            || !$this->validIdentifier($table)
            || !$this->validIdentifier($column)
        ) {
            return null;
        }

        return [$table, $column, $targetNullable, $source];
    }

    private function showCreateTable(string $table, ?string $connection): string
    {
        $rows = $this->db->connection($connection ?: null)->select(
            'SHOW CREATE TABLE ' . $this->quoteIdentifier($table)
        );

        if ($rows === []) {
            throw new \RuntimeException('Unable to read table DDL: ' . $table);
        }

        $values = array_values((array) $rows[0]);

        if (!isset($values[1]) || !is_string($values[1])) {
            throw new \RuntimeException('Unexpected SHOW CREATE TABLE response for: ' . $table);
        }

        return $values[1];
    }

    private function extractColumnDefinition(string $ddl, string $column): ?string
    {
        foreach (preg_split('/\r?\n/', $ddl) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\x60' . preg_quote($column, '/') . '\x60\s+/i', $trimmed) !== 1) {
                continue;
            }

            return rtrim($trimmed, ',');
        }

        return null;
    }

    private function extractForeignKeyDefinitions(string $ddl, string $column): array
    {
        $constraints = [];

        foreach (preg_split('/\r?\n/', $ddl) ?: [] as $line) {
            $trimmed = rtrim(trim($line), ',');

            if (
                preg_match(
                    '/^CONSTRAINT\s+\x60([^\x60]+)\x60\s+FOREIGN\s+KEY\s*\(([^)]+)\)/i',
                    $trimmed,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            if (preg_match('/\x60' . preg_quote($column, '/') . '\x60/i', $matches[2]) !== 1) {
                continue;
            }

            $constraints[] = [
                'name' => $matches[1],
                'definition' => $trimmed,
            ];
        }

        return $constraints;
    }

    private function hasConstraint(string $ddl, string $name): bool
    {
        return preg_match(
            '/CONSTRAINT\s+\x60' . preg_quote($name, '/') . '\x60\s+FOREIGN\s+KEY/i',
            $ddl
        ) === 1;
    }

    private function openJournal(
        ?string $connection,
        string $table,
        string $column,
        string $originalDefinition,
        string $targetDefinition,
        array $constraints,
        string $source
    ): string {
        if (!is_dir($this->journalPath)) {
            @mkdir($this->journalPath, 0755, true);
        }

        $id = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
        $file = rtrim($this->journalPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.json';

        $payload = [
            'id' => $id,
            'status' => 'running',
            'phase' => 'planned',
            'connection' => $connection,
            'table' => $table,
            'column' => $column,
            'source' => $source,
            'original_definition' => $originalDefinition,
            'target_definition' => $targetDefinition,
            'constraints' => $constraints,
            'started_at' => date(DATE_ATOM),
        ];

        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }

    private function updateJournal(
        string $file,
        string $phase,
        bool $complete = false,
        ?string $error = null
    ): void {
        $payload = json_decode((string) @file_get_contents($file), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $payload['phase'] = $phase;
        $payload['status'] = $complete ? 'complete' : ($phase === 'failed' ? 'failed' : 'running');
        $payload['updated_at'] = date(DATE_ATOM);

        if ($complete) {
            $payload['finished_at'] = date(DATE_ATOM);
        }

        if ($error !== null) {
            $payload['error'] = $error;
        }

        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!$this->validIdentifier($identifier)) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return chr(96) . $identifier . chr(96);
    }

    private function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-zA-Z0-9_$]+$/', $identifier) === 1;
    }
}
