<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\MaintenanceMode;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\Totp;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Argws\LaravelUpdater\Support\ReleaseNotesResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UpdaterUiController extends Controller
{
    public function __construct(
        private readonly ManagerStore $managerStore,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly Totp $totp,
        private readonly AuthStore $authStore,
        private readonly ReleaseNotesResolver $releaseNotesResolver
    ) {
    }

    public function index(UpdaterKernel $kernel)
    {
        $store = $kernel->stateStore();
        $store->ensureSchema();
        $lastRun = $store->lastRun();
        $runs = $store->recentRuns(20);

        try {
            $status = $kernel->status();
        } catch (Throwable $e) {
            $status = [
                'enabled' => (bool) config('updater.enabled', true),
                'mode' => (string) config('updater.mode', 'inplace'),
                'channel' => (string) config('updater.channel', 'stable'),
                'revision' => 'N/A',
                'last_run' => $lastRun,
                'warning' => 'Não foi possível carregar status completo: ' . $e->getMessage(),
            ];
        }

        return view('laravel-updater::dashboard', [
            'status' => $status,
            'lastRun' => $lastRun,
            'runs' => $runs,
            'branding' => $this->managerStore->resolvedBranding(),
            'activeProfile' => $this->managerStore->activeProfile(),
            'activeSource' => $this->managerStore->activeSource(),
            'versionBar' => $this->resolveVersionBarData($kernel, $status),
        ]);
    }

    public function check(UpdaterKernel $kernel, Request $request): JsonResponse
    {
        $status = $kernel->check((bool) $request->boolean('allow_dirty'));
        $status['latest_tag_release_notes_url'] = $this->releaseNotesResolver->resolve((string) ($this->managerStore->activeSource()['repo_url'] ?? ''), (string) ($status['latest_tag'] ?? ''));

        return response()->json($status);
    }

    public function status(UpdaterKernel $kernel): JsonResponse
    {
        return response()->json($kernel->status());
    }

    public function triggerUpdate(Request $request, TriggerDispatcher $dispatcher, UpdaterKernel $kernel): RedirectResponse
    {
        if ($kernel->stateStore()->hasActiveRun()) {
            return back()->withErrors(["update" => "Já existe uma execução em andamento. Aguarde finalizar para disparar outra."]);
        }

        $activeProfile = $this->managerStore->activeProfile();
        $dryRun = $request->input('action') === 'simulate'
            || $request->boolean('dry_run_before') || $request->boolean('dry_run')
            || (bool) ($activeProfile['dry_run'] ?? false);
        if (!$dryRun && $request->input('confirm_apply') !== 'ATUALIZAR') {
            return back()->withErrors(['confirm_apply' => 'Digite ATUALIZAR para confirmar a aplicação real.']);
        }
        $preUpdateCommands = $this->parseCommands((string) ($activeProfile['pre_update_commands'] ?? ''));
        $postUpdateCommands = $this->parseCommands((string) ($activeProfile['post_update_commands'] ?? ''));

        $dispatcher->triggerUpdate([
            'seed' => (bool) ($activeProfile['seed'] ?? false),
            'seeders' => $request->filled('seed') ? [$request->string('seed')->toString()] : [],
            'pre_update_commands' => $preUpdateCommands,
            'post_update_commands' => $postUpdateCommands,
            'allow_dirty' => false,
            'dry_run' => $dryRun,
            'rollback_on_fail' => (bool) ($activeProfile['rollback_on_fail'] ?? true),
            'profile_id' => $activeProfile['id'] ?? null,
            'source_id' => $this->managerStore->activeSource()['id'] ?? null,
            'check_only' => $request->boolean('check_only'),
            'allow_http' => true,
        ]);

        return back()->with('status', $dryRun ? 'Simulação disparada. Nenhuma alteração real será aplicada.' : 'Atualização real disparada com sucesso.');
    }

    /** @return array<int,string> */
    private function parseCommands(string $raw): array
    {
        $commands = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $commands[] = $line;
        }

        return array_values(array_unique($commands));
    }

    public function triggerRollback(TriggerDispatcher $dispatcher, UpdaterKernel $kernel): RedirectResponse
    {
        if ($kernel->stateStore()->hasActiveRun()) {
            return back()->withErrors(["rollback" => "Já existe uma execução em andamento. Aguarde finalizar para disparar rollback."]);
        }

        $dispatcher->triggerRollback();

        return back()->with('status', 'Rollback disparado com sucesso.');
    }


    public function maintenanceOn(Request $request, ShellRunner $shellRunner): RedirectResponse
    {
        $this->validateMaintenanceConfirmation($request);

        $view = (string) config('updater.maintenance.render_view', 'laravel-updater::maintenance');

        try {
            $shellRunner->runOrFail($this->maintenanceMode->downCommand($view, true));
        } catch (\Throwable $e) {
            if ($this->maintenanceMode->hasExceptOptionError($e)) {
                $shellRunner->runOrFail($this->maintenanceMode->downCommand($view, false));
            } else {
                throw $e;
            }
        }

        return back()->with('status', 'Modo manutenção habilitado com sucesso.');
    }

    public function maintenanceOff(Request $request, ShellRunner $shellRunner): RedirectResponse
    {
        $this->validateMaintenanceConfirmation($request);
        $shellRunner->runOrFail(['php', 'artisan', 'up']);

        return back()->with('status', 'Modo manutenção desabilitado com sucesso.');
    }

    private function validateMaintenanceConfirmation(Request $request): void
    {
        $data = $request->validate([
            'maintenance_confirmation' => ['required', 'string'],
            'maintenance_2fa_code' => ['nullable', 'string'],
        ], [
            'maintenance_confirmation.required' => 'Confirme a ação digitando MANUTENCAO.',
        ]);

        if (mb_strtoupper(trim((string) $data['maintenance_confirmation'])) !== 'MANTENCAO') {
            throw \Illuminate\Validation\ValidationException::withMessages(['maintenance_confirmation' => 'Confirmação inválida. Digite MANTENCAO para prosseguir.']);
        }

        if (!(bool) config('updater.ui.auth.enabled', false)) {
            return;
        }

        $user = (array) $request->attributes->get('updater_user', []);
        if (!((bool) ($user['totp_enabled'] ?? false))) {
            return;
        }

        $code = trim((string) ($data['maintenance_2fa_code'] ?? ''));
        if ($code === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['maintenance_2fa_code' => 'Informe o código 2FA para confirmar esta ação.']);
        }

        if (!$this->totp->verify((string) ($user['totp_secret'] ?? ''), $code) && !$this->authStore->consumeRecoveryCode((int) ($user['id'] ?? 0), $code)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['maintenance_2fa_code' => 'Código 2FA/recovery inválido.']);
        }
    }


    /**
     * Retrocompatibilidade com instalações antigas/publicadas que ainda chamam este método.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public function resolveVersionBarData(UpdaterKernel $kernel, array $status = []): array
    {
        $activeSource = $this->managerStore->activeSource();
        $available = 'n/d';
        $installed = 'n/d';

        if (class_exists('Composer\\InstalledVersions') && \Composer\InstalledVersions::isInstalled('argws/laravel-updater')) {
            $installed = \Composer\InstalledVersions::getPrettyVersion('argws/laravel-updater') ?: 'n/d';
        }

        $cacheKey = sprintf('updater:version_bar:%s:%s', (string) ($activeSource['id'] ?? 'none'), (string) ($status['channel'] ?? config('updater.channel', 'stable')));
        try {
            $available = (string) Cache::remember($cacheKey, now()->addMinutes(3), static function () use ($kernel): string {
                $check = $kernel->check(true);

                return (string) ($check['latest_tag'] ?? $check['remote'] ?? 'n/d');
            });
        } catch (\Throwable $e) {
            $available = (string) Cache::get($cacheKey, 'n/d');
        }

        return [
            'enabled' => true,
            'position' => 'top',
            'updater' => [
                'installed' => $installed,
                'latest' => $available,
            ],
            'application' => [
                'framework_version' => app()->version(),
                'git_revision' => (string) ($status['revision'] ?? 'n/d'),
                'git_tag' => (string) ($status['last_run']['revision_after'] ?? ''),
                'channel' => (string) ($status['channel'] ?? config('updater.channel', 'stable')),
                'source_name' => (string) ($activeSource['name'] ?? 'n/d'),
                'source_type' => (string) ($activeSource['type'] ?? 'n/d'),
            ],
        ];
    }

    public function apiTrigger(Request $request, TriggerDispatcher $dispatcher): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request, null)) !== null) {
            return $authError;
        }
        if (strlen($request->getContent()) > 16384) {
            return response()->json(['ok' => false, 'code' => 'payload_too_large', 'message' => 'Limite de 16 KiB excedido.'], 413);
        }
        foreach (['pre_update_commands', 'post_update_commands', 'commands', 'command', 'sql_patch'] as $unsafe) {
            if (!in_array($request->input($unsafe), [null, '', false, 0, '0'], true)) {
                return response()->json(['ok' => false, 'code' => 'unsafe_option', 'message' => 'Comandos e SQL remotos não são aceitos.'], 422);
            }
        }
        if (!in_array($request->input('seed'), [null, '', false, 0, '0'], true)) {
            return response()->json(['ok' => false, 'code' => 'unsafe_option', 'message' => 'Configure seeds no perfil confiável da ferramenta.'], 422);
        }
        $validation = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'profile_id' => ['nullable', 'integer', 'min:1'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
            'dry_run_before' => ['sometimes', 'boolean'],
            'action' => ['sometimes', 'in:simulate,apply'],
            'update_mode' => ['sometimes', 'in:merge,ff-only,tag,full-update'],
            'target_tag' => ['nullable', 'string', 'max:120', 'regex:/^(?!-)[^\x00-\x20\x7f]+$/u'],
            'target_branch' => ['nullable', 'string', 'max:255', 'regex:/^(?!-)[^\x00-\x20\x7f]+$/u'],
            'confirmation' => ['nullable', 'string', 'max:32'],
        ]);
        if ($validation->fails()) {
            return response()->json(['ok' => false, 'code' => 'validation_failed', 'errors' => $validation->errors()], 422);
        }
        $data = $validation->validated();
        $profile = isset($data['profile_id']) ? $this->managerStore->findProfile((int) $data['profile_id']) : $this->managerStore->activeProfile();
        $source = isset($data['source_id']) ? $this->managerStore->findSource((int) $data['source_id']) : $this->managerStore->activeSource();
        if ((isset($data['profile_id']) && $profile === null) || (isset($data['source_id']) && $source === null)) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Perfil ou fonte inexistente.'], 404);
        }
        $dryRun = ($data['action'] ?? '') === 'simulate' || $request->boolean('dry_run') || $request->boolean('dry_run_before') || (bool) ($profile['dry_run'] ?? false);
        $principal = (array) $request->attributes->get('updater_api_principal', []);
        if (!in_array($dryRun ? 'updates:simulate' : 'updates:apply', $principal['scopes'] ?? [], true)) {
            return response()->json(['ok' => false, 'code' => 'insufficient_scope', 'message' => 'Token sem escopo para esta operação.'], 403);
        }
        if ($request->filled('approval_run_id')) {
            return response()->json(['ok' => false, 'code' => 'approval_required', 'message' => 'A aprovação exige sessão e senha no painel.'], 409);
        }
        if (!$dryRun && ($data['confirmation'] ?? '') !== 'ATUALIZAR') {
            return response()->json(['ok' => false, 'code' => 'confirmation_required', 'message' => 'Para aplicar mudanças envie confirmation=ATUALIZAR. Para simular envie dry_run=true.'], 422);
        }
        $mode = (string) ($data['update_mode'] ?? config('updater.git.default_update_mode', 'merge'));
        if ($mode === 'tag' && empty($data['target_tag'])) {
            return response()->json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Informe target_tag para atualização por tag.'], 422);
        }
        if ($mode === 'full-update' && !(bool) config('updater.full_update.enabled', false)) {
            return response()->json(['ok' => false, 'code' => 'operation_disabled', 'message' => 'Atualização completa desabilitada.'], 403);
        }
        $options = [
            'profile_id' => $profile['id'] ?? null, 'source_id' => $source['id'] ?? null,
            'dry_run' => $dryRun, 'allow_dirty' => false,
            'update_type' => match ($mode) { 'ff-only' => 'git_ff_only', 'tag' => 'git_tag', 'full-update' => 'zip_release', default => 'git_merge' },
            'target_tag' => (string) ($data['target_tag'] ?? ''), 'target_branch' => (string) ($data['target_branch'] ?? ''),
            'rollback_on_fail' => (bool) ($profile['rollback_on_fail'] ?? true),
            'triggered_via' => 'api', 'allow_http' => true,
        ];
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        if ($idempotencyKey !== '' && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey) !== 1) {
            return response()->json(['ok' => false, 'code' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key deve ter 8 a 128 caracteres seguros.'], 422);
        }
        $reservation = null;
        $idempotency = app(\Argws\LaravelUpdater\Support\ApiIdempotencyStore::class);
        if ($idempotencyKey !== '') {
            $reservation = $idempotency->reserve((string) ($principal['id'] ?? ''), $idempotencyKey, $options);
            if (!$reservation['reserved']) {
                if (isset($reservation['response'])) {
                    return response()->json($reservation['response'])->header('Idempotency-Replayed', 'true');
                }
                return response()->json(['ok' => false, 'code' => isset($reservation['conflict']) ? 'idempotency_conflict' : 'request_in_progress', 'message' => 'A chave já foi utilizada; consulte as execuções antes de tentar novamente.'], 409);
            }
        }
        try {
            $runId = $dispatcher->triggerUpdate($options);
        } catch (Throwable) {
            // Keep a failed reservation: a crashed dispatcher may have created a run already.
            return response()->json(['ok' => false, 'code' => 'dispatch_unavailable', 'message' => 'Não foi possível iniciar. Confira o histórico antes de tentar novamente.'], 409);
        }
        $response = ['ok' => true, 'queued' => true, 'run_id' => $runId, 'options' => $options, 'requested_at' => now()->toIso8601String()];
        if (is_array($reservation)) {
            $idempotency->complete($reservation['key'], $response);
        }
        $this->managerStore->addAuditLog(null, 'api_update_requested', ['token_id' => $principal['id'] ?? null, 'run_id' => $runId, 'dry_run' => $dryRun], $request->ip());
        return response()->json($response);
    }

    public function apiStatus(Request $request, UpdaterKernel $kernel): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request, 'status:read')) !== null) {
            return $authError;
        }
        try {
            $data = $this->sanitizeApiData($kernel->status());
        } catch (Throwable) {
            return response()->json(['ok' => false, 'code' => 'status_unavailable', 'message' => 'Status indisponível temporariamente.'], 503);
        }
        return response()->json(['ok' => true, 'data' => $data, 'requested_at' => now()->toIso8601String()]);
    }

    public function apiCheck(Request $request, UpdaterKernel $kernel): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request, 'check:read')) !== null) {
            return $authError;
        }
        try {
            $status = $kernel->check((bool) $request->boolean('allow_dirty', true));
            $status['latest_tag_release_notes_url'] = $this->releaseNotesResolver->resolve((string) ($this->managerStore->activeSource()['repo_url'] ?? ''), (string) ($status['latest_tag'] ?? ''));
        } catch (Throwable) {
            return response()->json(['ok' => false, 'code' => 'check_unavailable', 'message' => 'Não foi possível consultar o repositório.'], 503);
        }
        return response()->json(['ok' => true, 'data' => $this->sanitizeApiData($status), 'requested_at' => now()->toIso8601String()]);
    }

    public function apiRuns(Request $request, UpdaterKernel $kernel): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request, 'runs:read')) !== null) {
            return $authError;
        }
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $runs = $kernel->stateStore()->recentRuns($limit);
        return response()->json(['ok' => true, 'count' => count($runs), 'data' => $this->sanitizeApiData($runs), 'requested_at' => now()->toIso8601String()]);
    }

    public function apiReleaseNotes(Request $request): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request, 'releases:read')) !== null) {
            return $authError;
        }
        return $this->releaseNotes($request);
    }

    public function releaseNotes(Request $request): JsonResponse
    {
        $validation = \Illuminate\Support\Facades\Validator::make($request->query(), [
            'source_id' => ['nullable', 'integer', 'min:1'],
            'tag' => ['required', 'string', 'max:120', 'regex:/^(?!-)[^\x00-\x20\x7f]+$/u'],
        ]);
        if ($validation->fails()) {
            return response()->json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Informe uma tag exata e uma fonte válidas.'], 422);
        }
        $tag = (string) $request->query('tag', '');
        if ($tag === '' || strlen($tag) > 120 || preg_match('/^(?!-)[^\x00-\x20\x7f]+$/u', $tag) !== 1) {
            return response()->json(['ok' => false, 'message' => 'Informe uma tag exata válida.'], 422);
        }
        $source = $request->filled('source_id') ? $this->managerStore->findSource((int) $request->query('source_id')) : $this->managerStore->activeSource();
        if ($request->filled('source_id') && $source === null) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Fonte inexistente.'], 404);
        }
        $source ??= [];
        $credential = (string) (($source['token_encrypted'] ?? '') ?: ($source['auth_password'] ?? ''));
        return response()->json($this->releaseNotesResolver->metadata((string) ($source['repo_url'] ?? config('updater.git.remote_url', '')), $tag, $credential === '' ? null : $credential) + ['source_id' => $source['id'] ?? null]);
    }

    public function apiOpenApi(Request $request): JsonResponse
    {
        if (($authError = $this->validateApiRequest($request, 'status:read')) !== null) {
            return $authError;
        }
        $document = json_decode((string) file_get_contents(__DIR__ . '/../../../resources/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['servers'] = [['url' => url(trim((string) config('updater.ui.prefix', '_updater'), '/'))]];
        return response()->json($document);
    }

    public function apiDocumentation()
    {
        $specification = json_decode((string) file_get_contents(__DIR__ . '/../../../resources/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $specification['servers'] = [['url' => url(trim((string) config('updater.ui.prefix', '_updater'), '/'))]];
        return view('laravel-updater::api.docs', [
            'specification' => $specification, 'endpoints' => $specification['paths'],
            'openapiUrl' => route('updater.api.openapi'),
        ]);
    }

    private function validateApiRequest(Request $request, ?string $scope): ?JsonResponse
    {
        $rateKey = 'updater:api:auth:' . hash('sha256', (string) $request->ip());
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, 120)) {
            return response()->json(['ok' => false, 'code' => 'rate_limited', 'message' => 'Muitas solicitações.'], 429)->header('Retry-After', '60');
        }
        \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 60);
        $plain = (string) ($request->bearerToken() ?: $request->header('X-Updater-Token', ''));
        $principal = app(\Argws\LaravelUpdater\Support\ApiTokenRegistry::class)->authenticate($plain);
        if ($principal === null) {
            return response()->json(['ok' => false, 'code' => 'unauthorized', 'message' => 'Token inválido'], 401);
        }
        if ($scope !== null && !in_array($scope, $principal['scopes'], true)) {
            return response()->json(['ok' => false, 'code' => 'insufficient_scope', 'message' => 'Token sem escopo para esta operação.'], 403);
        }
        $limitKey = 'updater:api:' . ($scope === null ? 'trigger:' : 'read:') . $principal['id'];
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limitKey, $scope === null ? 6 : 60)) {
            return response()->json(['ok' => false, 'code' => 'rate_limited', 'message' => 'Limite por token atingido.'], 429)->header('Retry-After', '60');
        }
        \Illuminate\Support\Facades\RateLimiter::hit($limitKey, 60);
        $request->attributes->set('updater_api_principal', $principal);
        return null;
    }

    /** Preserve the legacy JSON-string columns while redacting values within them. */
    private function sanitizeApiData(mixed $value, ?array $knownSecrets = null): mixed
    {
        if ($knownSecrets === null) {
            $knownSecrets = [];
            $remember = static function (array $data) use (&$remember, &$knownSecrets): void {
                foreach ($data as $key => $item) {
                    if (is_array($item)) {
                        $remember($item);
                    } elseif (is_string($item) && strlen($item) >= 4 && \Argws\LaravelUpdater\Support\SecretVault::sensitive((string) $key)) {
                        $knownSecrets[] = $item;
                    }
                }
            };
            $remember((array) config('updater', []));
            try {
                $remember($this->managerStore->sources());
                $remember($this->managerStore->backupUploadSettings());
            } catch (Throwable) {
                // Named fields are still scrubbed if credential storage is temporarily unavailable.
            }
            $knownSecrets = array_values(array_unique($knownSecrets));
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $sanitized = \Argws\LaravelUpdater\Support\RunDiagnosticsService::sanitize([$key => $item]);
                if (is_string($item) && is_array($sanitized[$key] ?? null)) {
                    $result[$key] = str_replace($knownSecrets, '[REDACTED]', (string) json_encode($sanitized[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } elseif (is_array($item) && is_array($sanitized[$key] ?? null) && !in_array($key, ['pre_update_commands', 'post_update_commands', 'post_update_commands_log'], true)) {
                    $result[$key] = $this->sanitizeApiData($item, $knownSecrets);
                } else {
                    $result[$key] = is_string($sanitized[$key] ?? null) ? str_replace($knownSecrets, '[REDACTED]', $sanitized[$key]) : ($sanitized[$key] ?? null);
                }
            }
            return $result;
        }
        $sanitized = \Argws\LaravelUpdater\Support\RunDiagnosticsService::sanitize($value);
        return is_string($sanitized) ? str_replace($knownSecrets, '[REDACTED]', $sanitized) : $sanitized;
    }

    public function assetCss()
    {
        if (request()->query('spa') === '1') {
            return app(\Argws\LaravelUpdater\Support\SpaAssets::class)->legacy('css');
        }
        $candidates = [
            __DIR__ . '/../../../resources/assets/updater.css',
            public_path('vendor/laravel-updater/updater.css'),
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'public, max-age=3600',
                    'Content-Type' => 'text/css; charset=UTF-8',
                ]);
            }
        }

        abort(404, 'Asset CSS do updater não encontrado.');
    }

    public function assetJs()
    {
        if (request()->query('spa') === '1') {
            return app(\Argws\LaravelUpdater\Support\SpaAssets::class)->legacy('js');
        }
        $candidates = [
            __DIR__ . '/../../../resources/assets/updater.js',
            public_path('vendor/laravel-updater/updater.js'),
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'public, max-age=3600',
                    'Content-Type' => 'application/javascript; charset=UTF-8',
                ]);
            }
        }

        abort(404, 'Asset JS do updater não encontrado.');
    }

    public function brandingLogo()
    {
        $branding = $this->managerStore->branding();
        if ($branding === null || empty($branding['logo_path']) || !Storage::exists((string) $branding['logo_path'])) {
            abort(404);
        }

        return response()->file(Storage::path((string) $branding['logo_path']), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }


    public function brandingMaintenanceLogo()
    {
        $branding = $this->managerStore->branding();
        if ($branding === null || empty($branding['maintenance_logo_path']) || !Storage::exists((string) $branding['maintenance_logo_path'])) {
            abort(404);
        }

        return response()->file(Storage::path((string) $branding['maintenance_logo_path']), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function brandingFavicon()
    {
        $branding = $this->managerStore->branding();
        if ($branding === null || empty($branding['favicon_path']) || !Storage::exists((string) $branding['favicon_path'])) {
            abort(404);
        }

        return response()->file(Storage::path((string) $branding['favicon_path']), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
