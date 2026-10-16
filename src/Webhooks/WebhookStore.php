<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Webhooks;

use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Contracts\Encryption\Encrypter;
use PDO;

class WebhookStore
{
    public const EVENTS = ['webhook.test', 'update.completed', 'update.failed', 'backup.completed'];
    public const ACTIONS = ['status', 'check', 'simulate'];

    private bool $ready = false;

    public function __construct(private readonly StateStore $state, private readonly Encrypter $encrypter)
    {
    }

    public function pdo(): PDO
    {
        $pdo = $this->state->pdo();
        if (!$this->ready) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS updater_webhook_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, direction TEXT NOT NULL,
                url TEXT NULL, events_json TEXT NOT NULL, actions_json TEXT NOT NULL,
                secret_cipher TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS updater_webhook_incoming (
                id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint_id INTEGER NOT NULL, event_id TEXT NOT NULL,
                body_hash TEXT NOT NULL, action TEXT NOT NULL, status TEXT NOT NULL,
                run_id INTEGER NULL, received_at TEXT NOT NULL, finished_at TEXT NULL,
                UNIQUE(endpoint_id,event_id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS updater_webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint_id INTEGER NOT NULL,
                event_id TEXT NOT NULL, event_type TEXT NOT NULL, payload_json TEXT NOT NULL,
                status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                response_code INTEGER NULL, error_code TEXT NULL, created_at TEXT NOT NULL,
                last_attempt_at INTEGER NULL, next_attempt_at INTEGER NULL,
                UNIQUE(endpoint_id,event_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS updater_webhook_due ON updater_webhook_deliveries(status,next_attempt_at)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS updater_webhook_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_id INTEGER NOT NULL, attempt INTEGER NOT NULL,
                status TEXT NOT NULL, response_code INTEGER NULL, error_code TEXT NULL,
                started_at TEXT NOT NULL, finished_at TEXT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS updater_webhook_observed (kind TEXT NOT NULL, object_id INTEGER NOT NULL,
                PRIMARY KEY(kind,object_id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS updater_webhook_rate (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
            $this->ready = true;
        }

        return $pdo;
    }

    public function endpoints(): array
    {
        return array_map(fn (array $row): array => $this->presentEndpoint($row), $this->pdo()->query('SELECT * FROM updater_webhook_endpoints ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function endpoint(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_webhook_endpoints WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['events'] = json_decode($row['events_json'], true) ?: [];
        $row['actions'] = json_decode($row['actions_json'], true) ?: [];

        return $row;
    }

    public function saveEndpoint(array $data, ?int $id = null): array
    {
        $now = gmdate(DATE_ATOM);
        $secret = $id === null ? bin2hex(random_bytes(32)) : null;
        if ($id === null) {
            $stmt = $this->pdo()->prepare('INSERT INTO updater_webhook_endpoints
                (name,direction,url,events_json,actions_json,secret_cipher,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$data['name'], $data['direction'], $data['url'] ?? null, json_encode($data['events']),
                json_encode($data['actions']), $this->encrypter->encrypt($secret, false), (int) $data['enabled'], $now, $now]);
            $id = (int) $this->pdo()->lastInsertId();
        } else {
            $stmt = $this->pdo()->prepare('UPDATE updater_webhook_endpoints SET name=?,direction=?,url=?,events_json=?,actions_json=?,enabled=?,updated_at=? WHERE id=?');
            $stmt->execute([$data['name'], $data['direction'], $data['url'] ?? null, json_encode($data['events']), json_encode($data['actions']), (int) $data['enabled'], $now, $id]);
        }

        return ['endpoint' => $this->presentEndpoint($this->endpoint($id)), 'secret' => $secret];
    }

    public function secret(array $endpoint): string
    {
        return (string) $this->encrypter->decrypt($endpoint['secret_cipher'], false);
    }

    public function rotate(int $id): string
    {
        $secret = bin2hex(random_bytes(32));
        $stmt = $this->pdo()->prepare('UPDATE updater_webhook_endpoints SET secret_cipher=?,updated_at=? WHERE id=?');
        $stmt->execute([$this->encrypter->encrypt($secret, false), gmdate(DATE_ATOM), $id]);

        return $secret;
    }

    public function delete(int $id): void
    {
        // Retain history; destruction removes the encrypted secret and cancels pending work.
        $stmt = $this->pdo()->prepare('DELETE FROM updater_webhook_endpoints WHERE id=?');
        $stmt->execute([$id]);
        $stmt = $this->pdo()->prepare("UPDATE updater_webhook_deliveries SET status='cancelled',error_code='endpoint_deleted',next_attempt_at=NULL WHERE endpoint_id=? AND status IN ('pending','retry','queued')");
        $stmt->execute([$id]);
    }

    private function presentEndpoint(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'direction' => $row['direction'],
            'url' => $row['url'], 'events' => $row['events'] ?? (json_decode($row['events_json'], true) ?: []),
            'actions' => $row['actions'] ?? (json_decode($row['actions_json'], true) ?: []),
            'enabled' => (bool) $row['enabled'], 'secret_configured' => $row['secret_cipher'] !== '',
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    public function history(array $filters = []): array
    {
        $where = []; $values = [];
        if (!empty($filters['status'])) { $where[] = 'status=?'; $values[] = $filters['status']; }
        if (!empty($filters['endpoint_id'])) { $where[] = 'endpoint_id=?'; $values[] = (int) $filters['endpoint_id']; }
        $stmt = $this->pdo()->prepare('SELECT id,endpoint_id,event_id,event_type,status,attempts,response_code,error_code,created_at,last_attempt_at,next_attempt_at FROM updater_webhook_deliveries' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 100');
        $stmt->execute($values);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo()->prepare('SELECT id,endpoint_id,event_id,action,status,run_id,received_at,finished_at FROM updater_webhook_incoming' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 100');
        $stmt->execute($values);

        $incoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_column($deliveries, 'id');
        $attempts = [];
        if ($ids !== []) {
            $attemptStmt = $this->pdo()->prepare('SELECT * FROM updater_webhook_attempts WHERE delivery_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 500');
            $attemptStmt->execute($ids);
            $attempts = $attemptStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return ['deliveries' => $deliveries, 'incoming' => $incoming, 'attempt_history' => $attempts];
    }

    /** Returns false for a previously accepted identifier. Uniqueness is enforced atomically in SQLite. */
    public function acceptIncoming(int $endpointId, string $eventId, string $hash, string $action): bool
    {
        $stmt = $this->pdo()->prepare("INSERT OR IGNORE INTO updater_webhook_incoming (endpoint_id,event_id,body_hash,action,status,received_at) VALUES (?,?,?,?,'processing',?)");
        $stmt->execute([$endpointId, $eventId, $hash, $action, gmdate(DATE_ATOM)]);

        return $stmt->rowCount() === 1;
    }

    public function incoming(int $endpointId, string $eventId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_webhook_incoming WHERE endpoint_id=? AND event_id=?');
        $stmt->execute([$endpointId, $eventId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function finishIncoming(int $endpointId, string $eventId, string $status, ?int $runId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_webhook_incoming SET status=?,run_id=?,finished_at=? WHERE endpoint_id=? AND event_id=?');
        $stmt->execute([$status, $runId, gmdate(DATE_ATOM), $endpointId, $eventId]);
    }

    public function rateAllowed(string $bucket, int $limit = 30): bool
    {
        $now = time();
        $this->pdo()->prepare('DELETE FROM updater_webhook_rate WHERE expires_at<?')->execute([$now]);
        $stmt = $this->pdo()->prepare('INSERT INTO updater_webhook_rate(bucket,count,expires_at) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1');
        $stmt->execute([$bucket, (int) (floor($now / 60) * 60) + 60]);
        $stmt = $this->pdo()->prepare('SELECT count FROM updater_webhook_rate WHERE bucket=?');
        $stmt->execute([$bucket]);

        return (int) $stmt->fetchColumn() <= $limit;
    }

    public function enqueue(int $endpointId, string $eventId, string $type, array $payload): void
    {
        $stmt = $this->pdo()->prepare("INSERT OR IGNORE INTO updater_webhook_deliveries (endpoint_id,event_id,event_type,payload_json,status,created_at,next_attempt_at) VALUES (?,?,?,?,'pending',?,?)");
        $stmt->execute([$endpointId, $eventId, $type, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), gmdate(DATE_ATOM), time()]);
    }

    public function delivery(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_webhook_deliveries WHERE id=?');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function due(): array
    {
        // A terminated queue worker can leave a processing lease. Retry retains the same event identifier.
        $this->pdo()->prepare("UPDATE updater_webhook_deliveries SET status='retry',error_code='worker_interrupted' WHERE status='processing' AND next_attempt_at<=?")->execute([time()]);
        $this->pdo()->exec("UPDATE updater_webhook_deliveries SET status='failed',next_attempt_at=NULL WHERE status='retry' AND attempts>=5");
        $stmt = $this->pdo()->prepare("SELECT * FROM updater_webhook_deliveries WHERE status IN ('pending','retry','queued') AND next_attempt_at<=? AND attempts<5 ORDER BY id LIMIT 25");
        $stmt->execute([time()]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reserve(int $id): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE updater_webhook_deliveries SET status='queued',next_attempt_at=? WHERE id=? AND status IN ('pending','retry','queued') AND next_attempt_at<=? AND attempts<5");
        $stmt->execute([time() + 120, $id, time()]);

        return $stmt->rowCount() === 1;
    }

    public function claim(int $id, int $attempt): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE updater_webhook_deliveries SET status='processing',attempts=?,last_attempt_at=?,next_attempt_at=? WHERE id=? AND status='queued' AND attempts=?");
        $stmt->execute([$attempt, time(), time() + 120, $id, $attempt - 1]);
        $claimed = $stmt->rowCount() === 1;
        if ($claimed) {
            $this->pdo()->prepare("INSERT INTO updater_webhook_attempts(delivery_id,attempt,status,started_at) VALUES(?,?,'processing',?)")->execute([$id, $attempt, gmdate(DATE_ATOM)]);
        }

        return $claimed;
    }

    public function complete(int $id, string $status, ?int $responseCode, ?string $errorCode, ?int $nextAttemptAt): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_webhook_deliveries SET status=?,response_code=?,error_code=?,next_attempt_at=? WHERE id=?');
        $stmt->execute([$status, $responseCode, $errorCode, $nextAttemptAt, $id]);
        $this->pdo()->prepare("UPDATE updater_webhook_attempts SET status=?,response_code=?,error_code=?,finished_at=? WHERE delivery_id=? AND status='processing'")
            ->execute([$status, $responseCode, $errorCode, gmdate(DATE_ATOM), $id]);
    }

    public function retry(int $id): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE updater_webhook_deliveries SET status='pending',attempts=0,error_code=NULL,next_attempt_at=? WHERE id=? AND status IN ('failed','delivered','cancelled')");
        $stmt->execute([time(), $id]);

        return $stmt->rowCount() === 1;
    }
}
