<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Argws\LaravelUpdater\Support\UiPermission;
use Argws\LaravelUpdater\Webhooks\WebhookDestination;
use Argws\LaravelUpdater\Webhooks\WebhookDispatcher;
use Argws\LaravelUpdater\Webhooks\WebhookReceiver;
use Argws\LaravelUpdater\Webhooks\WebhookStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebhookController
{
    public function __construct(private readonly WebhookStore $store, private readonly UiPermission $permission, private readonly ManagerStore $manager)
    {
    }

    public function index(Request $request)
    {
        $this->authorize($request);
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:30'], 'endpoint_id' => ['nullable', 'integer', 'min:1']]);
        $data = ['endpoints' => $this->store->endpoints(), ...$this->store->history($filters),
            'events' => WebhookStore::EVENTS, 'actions' => WebhookStore::ACTIONS, 'filters' => $filters];
        if ($request->expectsJson() && !$request->header('X-Updater-SPA')) {
            return $this->json(['data' => $data]);
        }

        return view('laravel-updater::webhooks.index', $data);
    }

    public function save(Request $request, WebhookDestination $destination): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100'],
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'url' => ['nullable', 'string', 'max:2048'],
            'events' => $request->input('direction') === 'outgoing' ? ['required', 'array', 'min:1', 'max:4'] : ['sometimes', 'array', 'max:4'],
            'events.*' => ['string', Rule::in(WebhookStore::EVENTS)],
            'actions' => $request->input('direction') === 'incoming' ? ['required', 'array', 'min:1', 'max:3'] : ['sometimes', 'array', 'max:3'],
            'actions.*' => ['string', Rule::in(WebhookStore::ACTIONS)],
            'enabled' => ['sometimes', 'boolean'],
        ], [
            'actions.required' => 'Selecione pelo menos uma ação permitida para o webhook recebido.',
            'actions.min' => 'Selecione pelo menos uma ação permitida para o webhook recebido.',
            'events.required' => 'Selecione pelo menos um evento. Para somente testes, selecione webhook.test.',
            'events.min' => 'Selecione pelo menos um evento. Para somente testes, selecione webhook.test.',
        ]);
        $id = isset($data['id']) ? (int) $data['id'] : null;
        $existing = $id === null ? null : $this->store->endpoint($id);
        abort_if($id !== null && $existing === null, 404);
        if ($existing && $existing['direction'] !== $data['direction']) {
            throw ValidationException::withMessages(['direction' => 'Crie outro endpoint para alterar a direção.']);
        }
        $data['events'] = array_values(array_unique($data['events'] ?? []));
        $data['actions'] = array_values(array_unique($data['actions'] ?? []));
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        if ($data['direction'] === 'outgoing') {
            try {
                $destination->resolve((string) ($data['url'] ?? ''));
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['url' => $error->getMessage()]);
            }
            if (parse_url((string) $data['url'], PHP_URL_QUERY) !== null) {
                throw ValidationException::withMessages(['url' => 'Use um destino sem parâmetros de URL; a autenticação utiliza o segredo HMAC.']);
            }
            $data['actions'] = [];
        } else {
            $data['url'] = null;
            $data['events'] = [];
        }
        $result = $this->store->saveEndpoint($data, $id);
        $this->audit($request, $id === null ? 'webhook.created' : 'webhook.updated', (int) $result['endpoint']['id']);
        if ($result['secret'] === null) {
            unset($result['secret']);
        }

        return $this->json(['ok' => true, ...$result], $id === null ? 201 : 200);
    }

    public function rotate(int $id, Request $request): JsonResponse
    {
        $this->authorize($request);
        abort_if($this->store->endpoint($id) === null, 404);
        $secret = $this->store->rotate($id);
        $this->audit($request, 'webhook.secret_rotated', $id);

        return $this->json(['ok' => true, 'secret' => $secret]);
    }

    public function delete(int $id, Request $request): JsonResponse
    {
        $this->authorize($request);
        abort_if($this->store->endpoint($id) === null, 404);
        $this->store->delete($id);
        $this->audit($request, 'webhook.deleted', $id);

        return $this->json(['ok' => true]);
    }

    public function test(int $id, Request $request, WebhookDispatcher $dispatcher): JsonResponse
    {
        $this->authorize($request);
        $endpoint = $this->store->endpoint($id);
        abort_if($endpoint === null, 404);
        abort_if($endpoint['direction'] !== 'outgoing' || !$endpoint['enabled'], 422, 'Habilite um destino de saída para testar.');
        $eventId = $dispatcher->publish('webhook.test', [], null, $id);
        $this->audit($request, 'webhook.test_queued', $id);

        return $this->json(['ok' => true, 'queued' => true, 'event_id' => $eventId], 202);
    }

    public function retry(int $id, Request $request): JsonResponse
    {
        $this->authorize($request);
        $delivery = $this->store->delivery($id);
        abort_if($delivery === null, 404);
        $endpoint = $this->store->endpoint((int) $delivery['endpoint_id']);
        abort_if($endpoint === null || !$endpoint['enabled'], 422, 'O destino precisa estar habilitado.');
        abort_unless($this->store->retry($id), 409, 'A entrega ainda está em andamento.');
        $this->audit($request, 'webhook.retry_queued', (int) $delivery['endpoint_id'], ['delivery_id' => $id]);

        return $this->json(['ok' => true, 'queued' => true], 202);
    }

    public function receive(int $endpointId, Request $request, WebhookReceiver $receiver): JsonResponse
    {
        if ((int) $request->header('Content-Length', 0) > 65536) {
            return $this->json(['ok' => false, 'message' => 'Evento excede o limite de 64 KiB.'], 413);
        }
        if (!$request->isJson()) {
            return $this->json(['ok' => false, 'message' => 'Use application/json.'], 415);
        }
        try {
            $result = $receiver->receive($endpointId, $request->getContent(),
            (string) $request->header('X-Updater-Timestamp', ''), (string) $request->header('X-Updater-Event-Id', ''),
            (string) $request->header('X-Updater-Signature', ''),
            static function (string $action, ?int $profileId): ?int {
                if ($action === 'status') {
                    app(UpdaterKernel::class)->status();
                    return null;
                }
                if ($action === 'check') {
                    app(UpdaterKernel::class)->check(true);
                    return null;
                }

                // No apply/seed/SQL/commands from remote payloads. Simulation is forced server-side.
                return app(TriggerDispatcher::class)->triggerUpdate(['dry_run' => true, 'profile_id' => $profileId,
                    'triggered_via' => 'webhook', 'allow_http' => true]);
            }, (string) $request->ip());
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Não foi possível receber o evento.'], 503);
        }

        return $this->json($result['data'], $result['status']);
    }

    private function authorize(Request $request): void
    {
        if ((bool) config('updater.ui.auth.enabled', false)) {
            $this->permission->authorize($request, 'settings.manage');
        }
    }

    private function audit(Request $request, string $action, int $id, array $extra = []): void
    {
        $actor = (array) $request->attributes->get('updater_user', []);
        $this->manager->addAuditLog(isset($actor['id']) ? (int) $actor['id'] : null, $action,
            ['endpoint_id' => $id, ...$extra], $request->ip(), $request->userAgent());
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, private']);
    }
}
