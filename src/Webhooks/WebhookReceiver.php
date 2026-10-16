<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Webhooks;

use Throwable;

class WebhookReceiver
{
    public function __construct(private readonly WebhookStore $store)
    {
    }

    /** The executor receives only a validated allowlisted action and an optional numeric profile. */
    public function receive(int $endpointId, string $body, string $timestamp, string $eventId, string $signature, callable $executor, string $source = ''): array
    {
        if (strlen($body) > 65536) {
            return $this->result(413, ['ok' => false, 'message' => 'Evento excede o limite de 64 KiB.']);
        }
        if (!$this->store->rateAllowed('ip:' . hash('sha256', $source), 60)) {
            return $this->result(429, ['ok' => false, 'message' => 'Limite de eventos excedido.']);
        }
        $endpoint = $this->store->endpoint($endpointId);
        try {
            $valid = $endpoint !== null && $endpoint['direction'] === 'incoming' && (bool) $endpoint['enabled']
                && WebhookSignature::verify($this->store->secret($endpoint), $timestamp, $eventId, $body, $signature);
        } catch (Throwable) {
            $valid = false;
        }
        if (!$valid) {
            return $this->result(401, ['ok' => false, 'message' => 'Evento não autenticado.']);
        }
        if (!$this->store->rateAllowed('endpoint:' . $endpointId, 30)) {
            return $this->result(429, ['ok' => false, 'message' => 'Limite de eventos excedido.']);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || array_is_list($data) || json_last_error() !== JSON_ERROR_NONE
            || array_diff(array_keys($data), ['action', 'profile_id']) !== []
            || !isset($data['action']) || !is_string($data['action'])
            || !in_array($data['action'], WebhookStore::ACTIONS, true)
            || !in_array($data['action'], $endpoint['actions'], true)
            || (isset($data['profile_id']) && (!is_int($data['profile_id']) || $data['profile_id'] < 1 || $data['action'] !== 'simulate'))) {
            return $this->result(422, ['ok' => false, 'message' => 'Ação ou parâmetros não permitidos.']);
        }
        $hash = hash('sha256', $body);
        if (!$this->store->acceptIncoming($endpointId, $eventId, $hash, $data['action'])) {
            $previous = $this->store->incoming($endpointId, $eventId);
            if (!hash_equals((string) ($previous['body_hash'] ?? ''), $hash)) {
                return $this->result(409, ['ok' => false, 'message' => 'Identificador de evento já utilizado.']);
            }

            return $this->result(200, ['ok' => true, 'duplicate' => true, 'event_id' => $eventId,
                'status' => $previous['status'], 'run_id' => $previous['run_id'] === null ? null : (int) $previous['run_id']]);
        }
        try {
            $runId = $executor($data['action'], $data['profile_id'] ?? null);
            $this->store->finishIncoming($endpointId, $eventId, 'accepted', is_int($runId) ? $runId : null);

            return $this->result(202, ['ok' => true, 'duplicate' => false, 'event_id' => $eventId,
                'status' => 'accepted', 'run_id' => is_int($runId) ? $runId : null]);
        } catch (Throwable) {
            // Never persist the exception message, raw request, or response from operational services.
            $this->store->finishIncoming($endpointId, $eventId, 'failed', null);

            return $this->result(503, ['ok' => false, 'event_id' => $eventId, 'message' => 'Não foi possível processar o evento. Consulte o histórico autenticado.']);
        }
    }

    private function result(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }
}
