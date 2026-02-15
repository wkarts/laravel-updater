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
        $message = mb_strtolower($throwable->getMessage());
        $code = (string) $throwable->getCode();

        if ($this->matchesAlreadyExists($message, $code)) {
            return self::ALREADY_EXISTS;
        }

        if ($this->matchesLockRetryable($message, $code)) {
            return self::LOCK_RETRYABLE;
        }

        return self::NON_RETRYABLE;
    }

    public function inferObject(Throwable $throwable): array
    {
        $message = $throwable->getMessage();

        $patterns = [
            'table' => "/table ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i",
            'view' => "/view ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i",
            'index' => "/(?:index|key) ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? (?:already exists|duplicate)/i",
            'column' => "/duplicate column name:? ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]?/i",
            'constraint' => "/constraint ['`\"]?([a-zA-Z0-9_.$-]+)['`\"]? already exists/i",
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return ['type' => $type, 'name' => $matches[1]];
            }
        }

        return ['type' => 'unknown', 'name' => null];
    }

    private function matchesAlreadyExists(string $message, string $code): bool
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

        $errorCodes = ['42s01', '42p07', '42701', '1060', '1050', '1061'];

        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return in_array(mb_strtolower($code), $errorCodes, true);
    }

    private function matchesLockRetryable(string $message, string $code): bool
    {
        $phrases = [
            'deadlock found',
            'lock wait timeout exceeded',
            'lock timeout',
            'metadata lock',
            'database is locked',
            'could not obtain lock',
            'serialization failure',
        ];

        $errorCodes = ['40001', '40p01', '1205', '1213'];

        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return in_array(mb_strtolower($code), $errorCodes, true);
    }
}
