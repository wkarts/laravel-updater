<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Webhooks;

use Argws\LaravelUpdater\Jobs\DeliverWebhookJob;
use Throwable;

class WebhookDispatcher
{
    public function __construct(private readonly WebhookStore $store, private readonly WebhookTransport $transport)
    {
    }

    /** Emits a small fixed contract; arbitrary log messages and operation payloads are never sent. */
    public function publish(string $event, array $data = [], ?string $eventId = null, ?int $onlyEndpoint = null, ?string $occurredAt = null): string
    {
        if (!in_array($event, WebhookStore::EVENTS, true)) {
            throw new \InvalidArgumentException('Evento não suportado.');
        }
        $eventId ??= bin2hex(random_bytes(16));
        $safe = [];
        foreach (['run_id', 'artifact_id'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0) {
                $safe[$key] = (int) $data[$key];
            }
        }
        if (isset($data['status']) && in_array($data['status'], ['success', 'failed', 'DRY_RUN'], true)) {
            $safe['status'] = $data['status'];
        }
        if (isset($data['backup_type']) && in_array($data['backup_type'], ['database', 'snapshot', 'full'], true)) {
            $safe['backup_type'] = $data['backup_type'];
        }
        $occurredAt ??= gmdate(DATE_ATOM);
        $payload = ['id' => $eventId, 'event' => $event, 'occurred_at' => $occurredAt, 'data' => $safe];
        foreach ($this->store->endpoints() as $endpoint) {
            if ($endpoint['direction'] !== 'outgoing' || !$endpoint['enabled']
                || ($onlyEndpoint !== null && $endpoint['id'] !== $onlyEndpoint)
                || ($event !== 'webhook.test' && !in_array($event, $endpoint['events'], true))
                || strtotime($endpoint['created_at']) > strtotime($occurredAt)) {
                continue;
            }
            $this->store->enqueue($endpoint['id'], $eventId, $event, $payload);
        }

        return $eventId;
    }

    public function processPending(): void
    {
        $this->observeOperations();
        foreach ($this->store->due() as $delivery) {
            $id = (int) $delivery['id'];
            if (!$this->store->reserve($id)) {
                continue;
            }
            $attempt = (int) $delivery['attempts'] + 1;
            $connection = (string) config('queue.default', 'sync');
            $driver = (string) config('queue.connections.' . $connection . '.driver', $connection);
            if ($driver !== 'sync' && $driver !== 'null') {
                try {
                    app('bus')->dispatch(new DeliverWebhookJob($id, $attempt));
                } catch (Throwable) {
                    $this->store->complete($id, 'retry', null, 'queue_unavailable', time() + 60);
                }
            } else {
                $this->deliver($id, $attempt);
            }
        }
    }

    public function deliver(int $id, int $attempt): void
    {
        if (!$this->store->claim($id, $attempt)) {
            return;
        }
        $delivery = $this->store->delivery($id);
        $endpoint = $this->store->endpoint((int) $delivery['endpoint_id']);
        if (!$endpoint || !$endpoint['enabled'] || $endpoint['direction'] !== 'outgoing') {
            $this->store->complete($id, 'cancelled', null, 'endpoint_disabled', null);
            return;
        }
        $code = null;
        $error = null;
        try {
            $code = $this->transport->send((string) $endpoint['url'], $this->store->secret($endpoint), $delivery['event_id'], $delivery['payload_json']);
            if ($code >= 200 && $code < 300) {
                $this->store->complete($id, 'delivered', $code, null, null);
                return;
            }
            $error = $code >= 300 && $code < 400 ? 'redirect_rejected' : 'http_error';
        } catch (\InvalidArgumentException) {
            $error = 'destination_rejected';
        } catch (Throwable $exception) {
            $error = in_array($exception->getMessage(), ['timeout', 'transport_unavailable'], true)
                ? $exception->getMessage() : 'transport_error';
        }
        // Redirects, invalid destinations and other permanent HTTP 4xx are not retried automatically.
        $retryable = $error !== 'destination_rejected' && $error !== 'redirect_rejected'
            && ($code === null || $code === 408 || $code === 429 || $code >= 500);
        $retry = $retryable && $attempt < 5;
        $delay = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200][$attempt] ?? 7200;
        $this->store->complete($id, $retry ? 'retry' : 'failed', $code, $error, $retry ? time() + $delay : null);
    }

    /** Scheduler bridges existing operation persistence without changing update/backup service contracts. */
    public function observeOperations(): void
    {
        $pdo = $this->store->pdo();
        foreach ([
            ['run', "SELECT r.id,r.status,r.options_json,r.finished_at AS occurred_at FROM runs r LEFT JOIN updater_webhook_observed o ON o.kind='run' AND o.object_id=r.id WHERE o.object_id IS NULL AND r.status IN ('success','failed') AND r.finished_at IS NOT NULL AND julianday(r.finished_at)>=julianday((SELECT MIN(created_at) FROM updater_webhook_endpoints WHERE direction='outgoing')) ORDER BY r.id LIMIT 100"],
            ['backup', "SELECT b.id,b.type,b.run_id,b.created_at AS occurred_at FROM updater_backups b LEFT JOIN updater_webhook_observed o ON o.kind='backup' AND o.object_id=b.id WHERE o.object_id IS NULL AND julianday(b.created_at)>=julianday((SELECT MIN(created_at) FROM updater_webhook_endpoints WHERE direction='outgoing')) ORDER BY b.id LIMIT 100"],
        ] as [$kind, $query]) {
            foreach ($pdo->query($query)->fetchAll(\PDO::FETCH_ASSOC) as $object) {
                $options = $kind === 'run' ? json_decode((string) ($object['options_json'] ?? ''), true) : [];
                $options = is_array($options) ? $options : [];
                if (!empty($options['dry_run']) || !empty($options['rollback']) || !empty($options['manual_backup']) || !empty($options['manual_migration_reapply'])) {
                    $pdo->prepare('INSERT OR IGNORE INTO updater_webhook_observed(kind,object_id) VALUES(?,?)')->execute([$kind, $object['id']]);
                    continue;
                }
                $event = $kind === 'run' ? ($object['status'] === 'failed' ? 'update.failed' : 'update.completed') : 'backup.completed';
                $eventId = hash('sha256', $kind . ':' . $object['id'] . ':' . $event);
                $data = $kind === 'run' ? ['run_id' => $object['id'], 'status' => $object['status']]
                    : ['artifact_id' => $object['id'], 'run_id' => $object['run_id'], 'backup_type' => $object['type']];
                // Enqueue is unique per destination + event. A crash before observation is recorded is safe.
                $this->publish($event, $data, $eventId, null, $object['occurred_at']);
                $pdo->prepare('INSERT OR IGNORE INTO updater_webhook_observed(kind,object_id) VALUES(?,?)')->execute([$kind, $object['id']]);
            }
        }
    }
}
