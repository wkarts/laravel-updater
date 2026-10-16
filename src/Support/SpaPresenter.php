<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Explicit presentation boundary: no raw configuration or database row reaches Vue. */
class SpaPresenter
{
    private const PAGES = [
        'login' => [], 'twofactor' => [], 'maintenance' => [],
        'dashboard' => ['status', 'lastRun', 'runs', 'branding', 'activeProfile', 'activeSource', 'versionBar'],
        'profile' => ['user', 'pendingTotpSecret', 'otpauthUri', 'qrcodeDataUri', 'recoverySummary', 'newRecoveryCodes'],
        'sections.updates' => ['profiles', 'activeProfile', 'sources', 'activeSource', 'statusCheck', 'availableTags', 'fullUpdateEnabled', 'defaultUpdateMode', 'recoveryState'],
        'sections.runs' => ['runs'], 'sections.sources' => ['sources', 'editingSource', 'allowMultipleSources'],
        'sections.backups' => ['backups'], 'sections.logs' => ['logs'],
        'sections.security' => ['gitSizeBytes', 'gitMaintenanceEnabled', 'lockInfo', 'recoveryState'],
        'sections.seeds' => ['seeds'], 'sections.migrations' => ['rows', 'all_rows', 'metrics', 'runs', 'filters'],
        'sections.migrations-show' => ['item', 'attempts', 'reconciliations', 'reapplyQueue', 'logs'],
        'runs.show' => ['run', 'diagnostics', 'logs', 'pendingApproval'], 'backups.restore' => ['backup'],
        'users.index' => ['users'], 'users.create' => ['permissionDefinitions', 'masterEmail'],
        'users.edit' => ['user', 'permissionDefinitions', 'masterEmail'],
        'profiles.index' => ['profiles'], 'profiles.create' => ['profile'], 'profiles.edit' => ['profile'],
        'settings.index' => ['branding', 'tokens', 'sources', 'activeSource', 'profiles', 'backupUpload', 'updaterConfigFields'],
        'settings.env' => ['envFields'],
        'webhooks.index' => ['endpoints', 'deliveries', 'incoming', 'events', 'actions', 'filters', 'attempt_history'],
        'api.docs' => ['specification', 'endpoints', 'openapiUrl'],
        'error' => ['status', 'message'],
    ];
    private const SOURCE = ['id', 'name', 'type', 'repo_url', 'branch', 'auth_mode', 'auth_username', 'active', 'created_at', 'updated_at', 'auth_password_configured', 'token_configured', 'token_encrypted_configured', 'ssh_private_key_path_configured'];
    private const USER = ['id', 'name', 'email', 'is_admin', 'is_master', 'is_active', 'totp_enabled', 'permissions', 'permissions_json', 'created_at', 'updated_at', 'last_login_at'];
    private const TOKEN = ['id', 'name', 'prefix', 'token_prefix', 'scopes', 'scopes_json', 'created_at', 'last_used_at', 'revoked_at', 'expires_at', 'legacy_scopes'];
    private const RUN = ['id', 'status', 'started_at', 'finished_at', 'revision_before', 'revision_after', 'created_at', 'updated_at', 'current_step', 'heartbeat_at', 'worker_pid', 'dry_run', 'mode', 'channel', 'target_tag', 'target_branch', 'type'];
    private const INSTALLED_RELEASE = ['version', 'tag', 'base_stable_tag', 'git_commit_sha', 'release_channel', 'build_number', 'source'];
    private array $secrets = [];

    public function present(string $page, array $original, Request $request): array
    {
        $this->secrets = [];
        $this->rememberSecrets($original);
        $this->rememberSecrets((array) config('updater', []));
        try {
            $store = app(ManagerStore::class);
            $this->rememberSecrets($store->sources());
            $this->rememberSecrets($store->backupUploadSettings());
        } catch (\Throwable) { /* Presentation cannot turn a storage failure into a secret leak. */ }
        $data = [];
        foreach (self::PAGES[$page] ?? [] as $field) {
            if (array_key_exists($field, $original)) {
                $data[$field] = $this->field($field, $original[$field]);
            }
        }
        if ($page === 'sections.logs') {
            $data['filters'] = $this->clean($request->only(['run_id', 'level', 'q']));
        }
        if ($page === 'profile') {
            if (!empty($original['user']['totp_enabled'])) {
                unset($data['pendingTotpSecret'], $data['otpauthUri'], $data['qrcodeDataUri']);
            } else {
                // A newly generated setup secret is the only permitted 2FA secret in a read response.
                foreach (['pendingTotpSecret', 'otpauthUri', 'qrcodeDataUri'] as $field) {
                    $data[$field] = (string) ($original[$field] ?? '');
                }
            }
            $data['newRecoveryCodes'] = array_values(array_filter((array) ($original['newRecoveryCodes'] ?? []), 'is_string'));
            if ($request->hasSession()) {
                $request->session()->forget('updater_new_recovery_codes');
            }
        }
        $branding = [];
        try { $branding = $this->branding(app(ManagerStore::class)->resolvedBranding()); } catch (\Throwable) {}
        $user = (array) $request->attributes->get('updater_user', []);
        $permissions = app(UiPermission::class);
        $user = $this->pick($user, self::USER);
        $user['permissions'] = (bool) config('updater.ui.auth.enabled', false)
            ? array_values(array_filter(array_keys($permissions->definitions()), fn ($p) => $permissions->has($user, $p)))
            : array_keys($permissions->definitions());
        $flash = [];
        if ($request->hasSession()) {
            foreach (['status', 'success', 'error'] as $key) {
                if ($request->session()->has($key)) { $flash[$key] = $this->clean($request->session()->get($key)); }
            }
            $errors = $request->session()->get('errors');
            $flash['errors'] = is_object($errors) && method_exists($errors, 'getBag') ? $this->clean($errors->getBag('default')->messages()) : [];
            // pull also prevents a refresh from recovering a previously created API token.
            if ($request->session()->has('token_plain')) { $flash['token_plain'] = (string) $request->session()->pull('token_plain'); }
        }
        $basePath = Route::has('updater.index') ? (parse_url(route('updater.index'), PHP_URL_PATH) ?: '/') : '/' . trim((string) config('updater.ui.prefix', '_updater'), '/');
        return ['page' => $page, 'data' => $data, 'context' => [
            'basePath' => rtrim($basePath, '/'), 'csrfToken' => $request->hasSession() ? $request->session()->token() : '',
            'permissions' => array_fill_keys($user['permissions'], true), 'user' => $user, 'routes' => $this->routes(), 'flash' => $flash, 'branding' => $branding,
            'authEnabled' => (bool) config('updater.ui.auth.enabled', false),
            'security' => ['twoFactorEnabled' => (bool) config('updater.ui.auth.2fa.enabled', true), 'twoFactorRequired' => (bool) config('updater.ui.auth.2fa.required', false), 'maintenanceTwoFactorRequired' => (bool) config('updater.ui.auth.enabled', false) && (bool) ($user['totp_enabled'] ?? false)],
        ]];
    }

    /** Sanitize operational JSON too; named action contracts and status codes remain intact. */
    public function sanitizeResponse(mixed $value): mixed
    {
        $this->secrets = [];
        $this->rememberSecrets((array) config('updater', []));
        try { $this->rememberSecrets(app(ManagerStore::class)->sources()); $this->rememberSecrets(app(ManagerStore::class)->backupUploadSettings()); } catch (\Throwable) {}
        return $this->clean($value);
    }

    private function field(string $key, mixed $value): mixed
    {
        if (in_array($key, ['sources', 'users', 'tokens', 'runs'], true)) {
            $single = match ($key) { 'sources' => 'source', 'users' => 'user', 'tokens' => 'token', default => 'run' };
            return array_map(fn ($row) => $this->field($single, $row), (array) $value);
        }
        if (in_array($key, ['source', 'activeSource', 'editingSource'], true)) {
            if (!is_array($value)) { return null; }
            $safe = $this->pick($value, self::SOURCE);
            foreach (['auth_password', 'token_encrypted', 'ssh_private_key_path'] as $secret) {
                $safe[$secret . '_configured'] = (bool) ($value[$secret . '_configured'] ?? !empty($value[$secret]));
            }
            $safe['token_configured'] = $safe['token_encrypted_configured'] || (bool) ($value['token_configured'] ?? false);
            return $this->clean($safe);
        }
        if ($key === 'user') { return $this->clean($this->pick((array) $value, self::USER)); }
        if ($key === 'token') { return $this->clean($this->pick((array) $value, self::TOKEN)); }
        if (in_array($key, ['run', 'lastRun'], true)) { return is_array($value) ? $this->clean($this->pick($value, self::RUN)) : null; }
        if ($key === 'branding') { return $this->branding((array) $value); }
        if (in_array($key, ['updaterConfigFields', 'envFields'], true)) {
            return array_map(function ($row) {
                $row = (array) $row;
                $row = $this->pick($row, ['group', 'label', 'config', 'field', 'type', 'sensitive', 'env_keys', 'primary_env_key', 'env_locked', 'locked', 'value', 'env_value', 'is_set', 'configured', 'description']);
                if (!empty($row['sensitive']) || preg_match('/password|token|secret|private|key/i', (string) ($row['config'] ?? $row['field'] ?? ''))) {
                    $row['configured'] = (bool) ($row['configured'] ?? (!empty($row['value']) || !empty($row['env_value'])));
                    $row['value'] = ''; $row['env_value'] = '';
                }
                return $this->clean($row);
            }, (array) $value);
        }
        if ($key === 'status' && is_array($value)) {
            $safe = $this->pick((array) $value, ['enabled', 'mode', 'channel', 'revision', 'warning', 'installed_version', 'installed_release']);
            $safe['last_run'] = $this->field('run', $value['last_run'] ?? null);
            return $this->clean($safe);
        }
        if (in_array($key, ['profiles', 'activeProfile', 'profile'], true)) {
            if ($key === 'profiles') { return array_map(fn ($r) => $this->field('profile', $r), (array) $value); }
            if (!is_array($value)) { return null; }
            $safe = $this->pick($value, ['id', 'name', 'backup_enabled', 'dry_run', 'force', 'composer_install', 'migrate', 'seed', 'build_assets', 'health_check', 'rollback_on_fail', 'snapshot_include_vendor', 'snapshot_compression', 'retention_backups', 'active', 'created_at', 'updated_at']);
            foreach (['pre_update_commands', 'post_update_commands'] as $command) { $safe[$command . '_configured'] = !empty($value[$command]); }
            return $this->clean($safe);
        }
        return $this->clean($value);
    }

    private function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 12 || is_object($value)) { return null; }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if ($key === 'installed_release') {
                    $result[$key] = is_array($item) ? $this->clean($this->pick($item, self::INSTALLED_RELEASE), $depth + 1) : null;
                    continue;
                }
                if (is_string($key) && preg_match('/(?:^secret$|^token$|password|token_encrypted|token_hash|totp_secret|private_key|secret_key|access_key|client_secret|refresh_token|access_token|authorization|cookie|signature|options_json|error_json|pre_update_commands|post_update_commands)/i', $key) && !str_ends_with($key, '_configured')) { continue; }
                $result[$key] = $this->clean($item, $depth + 1);
            }
            return $result;
        }
        if (!is_string($value)) { return $value; }
        foreach ($this->secrets as $secret) { $value = str_replace($secret, '[REDACTED]', $value); }
        return RunDiagnosticsService::sanitize($value);
    }

    private function rememberSecrets(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) { $this->rememberSecrets($value); }
            elseif (is_string($key) && preg_match('/password|token|secret|private_key|access_key|client_secret|authorization/i', $key) && is_string($value) && strlen($value) >= 3) { $this->secrets[] = $value; }
        }
        $this->secrets = array_values(array_unique($this->secrets));
    }

    private function pick(array $data, array $allowed): array { return array_intersect_key($data, array_flip($allowed)); }

    private function branding(array $data): array
    {
        $original = $data;
        $data = $this->pick($data, ['app_name', 'app_sufix_name', 'app_name_full', 'app_desc', 'primary_color', 'maintenance_title', 'maintenance_message', 'maintenance_footer', 'first_run_assume_behind', 'first_run_assume_behind_commits', 'enter_maintenance_on_update_start']);
        foreach (['logo' => 'updater.branding.logo', 'favicon' => 'updater.branding.favicon', 'maintenance_logo' => 'updater.branding.maintenance_logo'] as $asset => $route) {
            $external = (string) ($original[$asset . '_url'] ?? '');
            $externalAllowed = filter_var($external, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($external, PHP_URL_SCHEME)), ['http', 'https'], true);
            $local = !empty($original[$asset . '_path']) && Route::has($route);
            $data[$asset . '_configured'] = $local || $externalAllowed;
            $data[$asset . '_url'] = $local ? route($route) : ($externalAllowed ? $external : null);
        }
        return $this->clean($data);
    }

    private function routes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'updater.')) { $routes[$name] = url($route->uri()); }
        }
        return $routes;
    }
}
