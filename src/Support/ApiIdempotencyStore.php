<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use PDO;

/** SQLite uniqueness reserves an event across PHP workers before dispatch. */
class ApiIdempotencyStore
{
    public function __construct(private readonly StateStore $state)
    {
    }

    public function reserve(string $principal, string $key, array $payload): array
    {
        $pdo = $this->state->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS updater_api_requests (request_key TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, response_json TEXT NULL, created_at INTEGER NOT NULL)');
        $pdo->prepare('DELETE FROM updater_api_requests WHERE created_at < ?')->execute([time() - 86400]);
        $requestKey = hash('sha256', $principal . "\0" . $key);
        ksort($payload);
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $insert = $pdo->prepare('INSERT OR IGNORE INTO updater_api_requests (request_key,fingerprint,created_at) VALUES (?,?,?)');
        $insert->execute([$requestKey, $fingerprint, time()]);
        if ($insert->rowCount() === 1) {
            return ['reserved' => true, 'key' => $requestKey];
        }
        $query = $pdo->prepare('SELECT fingerprint,response_json FROM updater_api_requests WHERE request_key = ?');
        $query->execute([$requestKey]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['fingerprint'], $fingerprint)) {
            return ['reserved' => false, 'conflict' => true];
        }
        return ['reserved' => false, 'response' => $row['response_json'] ? json_decode((string) $row['response_json'], true, 512, JSON_THROW_ON_ERROR) : null];
    }

    public function complete(string $key, array $response): void
    {
        $this->state->pdo()->prepare('UPDATE updater_api_requests SET response_json = ? WHERE request_key = ?')
            ->execute([json_encode($response, JSON_THROW_ON_ERROR), $key]);
    }
}
