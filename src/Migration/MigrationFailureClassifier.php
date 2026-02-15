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
        $details = $this->extractErrorDetails($throwable);

        if ($this->matchesIdempotentSafe($details['message'], $details['sqlstate'], $details['errno'])) {
            return self::ALREADY_EXISTS;
        }

        if ($this->matchesLockRetryable($details['message'], $details['sqlstate'], $details['errno'])) {
            return self::LOCK_RETRYABLE;
        }

        return self::NON_RETRYABLE;
    }

    /** @return array{type:string,name:?string,table:?string,expects_absent:bool} */
    public function inferObject(Throwable $throwable): array
    {
        $message = $throwable->getMessage();
        $table = $this->inferTableName($message);
        $messageLower = mb_strtolower($message);

        $patterns = [
            ['type' => 'table', 'pattern' => "/table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i", 'expects_absent' => false],
            ['type' => 'view', 'pattern' => "/view ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i", 'expects_absent' => false],
            ['type' => 'column', 'pattern' => "/duplicate column name:? ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i", 'expects_absent' => false],
            ['type' => 'index', 'pattern' => "/duplicate key name ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i", 'expects_absent' => false],
            ['type' => 'constraint', 'pattern' => "/duplicate foreign key constraint name ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i", 'expects_absent' => false],
            ['type' => 'index', 'pattern' => "/can't drop ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?; check that column\/key exists/i", 'expects_absent' => true],
            ['type' => 'table', 'pattern' => "/table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? doesn't exist/i", 'expects_absent' => true],
        ];

        foreach ($patterns as $rule) {
            if (preg_match($rule['pattern'], $message, $matches) === 1) {
                return [
                    'type' => $rule['type'],
                    'name' => $matches[1],
                    'table' => $table,
                    'expects_absent' => (bool) $rule['expects_absent'],
                ];
            }
        }

        return [
            'type' => str_contains($messageLower, 'doesn\'t exist') ? 'table' : 'unknown',
            'name' => str_contains($messageLower, 'doesn\'t exist') ? $table : null,
            'table' => $table,
            'expects_absent' => str_contains($messageLower, 'doesn\'t exist'),
        ];
    }

    /** @return array{sqlstate:?string,errno:?int,message:string} */
    public function extractErrorDetails(Throwable $throwable): array
    {
        $message = $throwable->getMessage();
        $sqlstate = null;
        $errno = is_numeric((string) $throwable->getCode()) ? (int) $throwable->getCode() : null;

        if (preg_match('/SQLSTATE\[([A-Z0-9]+)\]/i', $message, $matches) === 1) {
            $sqlstate = mb_strtoupper($matches[1]);
        }

        if (preg_match('/:\s*(\d{3,5})\s+/i', $message, $matches) === 1) {
            $errno = (int) $matches[1];
        }

        return [
            'sqlstate' => $sqlstate,
            'errno' => $errno,
            'message' => mb_strtolower($message),
        ];
    }

    private function matchesIdempotentSafe(string $message, ?string $sqlstate, ?int $errno): bool
    {
        $phrases = [
            'already exists',
            'base table or view already exists',
            'duplicate column name',
            'duplicate key name',
            'duplicate foreign key constraint name',
            'duplicate key on write or update',
            "can't drop",
            'check that column/key exists',
            'relation already exists',
            'duplicate object',
            'there is already an object named',
        ];

        $sqlStates = ['42S01', '42S21', '42S11', '42P07', '42701'];
        $errnoList = [1050, 1060, 1061, 1091, 1826];

        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                if ($errno === 121 && !str_contains($message, 'constraint')) {
                    return false;
                }

                return true;
            }
        }

        if (in_array($sqlstate, $sqlStates, true) || in_array($errno, $errnoList, true)) {
            return true;
        }

        if ($errno === 121 && str_contains($message, 'constraint')) {
            return true;
        }

        return $this->matchesMissingObjectSafe($message, $sqlstate, $errno);
    }

    private function matchesMissingObjectSafe(string $message, ?string $sqlstate, ?int $errno): bool
    {
        $isMissingObject = str_contains($message, "doesn't exist") || str_contains($message, 'does not exist');
        $isMissingCode = in_array($sqlstate, ['42S02'], true) || $errno === 1146;

        if (!$isMissingObject && !$isMissingCode) {
            return false;
        }

        $safeDropOperations = [
            'drop table',
            'drop index',
            'drop view',
            'alter table',
            'drop foreign key',
            'drop constraint',
        ];

        foreach ($safeDropOperations as $operation) {
            if (str_contains($message, $operation)) {
                return true;
            }
        }

        return false;
    }

    private function matchesLockRetryable(string $message, ?string $sqlstate, ?int $errno): bool
    {
        $phrases = [
            'deadlock found',
            'lock wait timeout exceeded',
            'try restarting transaction',
            'metadata lock',
            'database is locked',
            'could not obtain lock',
            'serialization failure',
        ];

        $sqlStates = ['40001', '40P01'];
        $errnoList = [1205, 1213];

        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return in_array($sqlstate, $sqlStates, true) || in_array($errno, $errnoList, true);
    }

    private function inferTableName(string $message): ?string
    {
        if (preg_match("/alter table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i", $message, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match("/table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i", $message, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match("/on table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i", $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
