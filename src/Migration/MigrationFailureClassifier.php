<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Migration;

use Throwable;

class MigrationFailureClassifier
{
    public const ALREADY_EXISTS = 'already_exists';
    public const LOCK_RETRYABLE = 'lock_retryable';
    public const NON_RETRYABLE = 'non_retryable';

    public function classify(Throwable $throwable): string
    {
        return $this->classifyWithContext($throwable)['classification'];
    }

    /**
     * @return array{classification:string,sqlstate:?string,errno:?int,message:string}
     */
    public function classifyWithContext(Throwable $throwable): array
    {
        $message = mb_strtolower($throwable->getMessage());
        $sqlstate = $this->extractSqlState($message);
        $errno = $this->extractErrno($throwable, $message);

        if ($this->matchesAlreadyExists($message, $sqlstate, $errno)) {
            return ['classification' => self::ALREADY_EXISTS, 'sqlstate' => $sqlstate, 'errno' => $errno, 'message' => $message];
        }

        if ($this->matchesLockRetryable($message, $sqlstate, $errno)) {
            return ['classification' => self::LOCK_RETRYABLE, 'sqlstate' => $sqlstate, 'errno' => $errno, 'message' => $message];
        }

        return ['classification' => self::NON_RETRYABLE, 'sqlstate' => $sqlstate, 'errno' => $errno, 'message' => $message];
    }

    public function inferObject(Throwable $throwable): array
    {
        $message = $throwable->getMessage();

        $patterns = [
            'table' => "/table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i",
            'view' => "/view ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i",
            'index' => "/(?:index|key) ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? (?:already exists|duplicate)/i",
            'column' => "/duplicate column name:? ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i",
            'constraint' => "/(?:constraint|foreign key) ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? (?:already exists|duplicate)/i",
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return ['type' => $type, 'name' => $matches[1], 'table' => $this->inferTable($message)];
            }
        }

        return ['type' => 'unknown', 'name' => null, 'table' => $this->inferTable($message)];
    }

    private function matchesAlreadyExists(string $message, ?string $sqlstate, ?int $errno): bool
    {
        $phrases = [
            'already exists',
            'base table or view already exists',
            'duplicate column',
            'duplicate key name',
            'relation already exists',
            'duplicate object',
            'there is already an object named',
            'duplicate constraint name',
            'duplicate index',
        ];

        $sqlStates = ['42s01', '42s21', '42s11', '42000', '42p07', '42701'];
        $errnoList = [1050, 1060, 1061, 1826];

        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        if ($errno === 121 && str_contains($message, 'constraint')) {
            return true;
        }

        if ($errno !== null && in_array($errno, $errnoList, true)) {
            return true;
        }

        return $sqlstate !== null && in_array(mb_strtolower($sqlstate), $sqlStates, true);
    }

    private function matchesLockRetryable(string $message, ?string $sqlstate, ?int $errno): bool
    {
        $phrases = [
            'deadlock found',
            'lock wait timeout exceeded',
            'lock timeout',
            'metadata lock',
            'database is locked',
            'could not obtain lock',
            'serialization failure',
            'try restarting transaction',
        ];

        $sqlStates = ['40001', '40p01'];
        $errnoList = [1205, 1213];

        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        if ($errno !== null && in_array($errno, $errnoList, true)) {
            return true;
        }

        return $sqlstate !== null && in_array(mb_strtolower($sqlstate), $sqlStates, true);
    }

    private function extractSqlState(string $message): ?string
    {
        if (preg_match('/sqlstate\[([a-z0-9]{5})\]/i', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractErrno(Throwable $throwable, string $message): ?int
    {
        if (is_numeric($throwable->getCode())) {
            return (int) $throwable->getCode();
        }

        if (preg_match('/:\s*(\d{3,5})\s+/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function inferTable(string $message): ?string
    {
        if (preg_match('/(?:on table|table)\s+["`\']?([a-z0-9_.$-]+)["`\']?/i', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
