<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\GitMaintenance;
use Argws\LaravelUpdater\Support\UpdaterLockTools;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\UiPermission;
use Argws\LaravelUpdater\Support\ReleaseNotesResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ManagerController extends Controller
{
    public function __construct(private readonly ManagerStore $managerStore, private readonly UiPermission $permission, private readonly ReleaseNotesResolver $releaseNotesResolver)
    {
    }

    public function section(string $section, Request $request)
    {
        return match ($section) {
            'updates' => view('laravel-updater::sections.updates', [
                'profiles' => $this->managerStore->profiles(),
                'activeProfile' => $this->managerStore->activeProfile(),
                'sources' => $this->managerStore->sources(),
                'activeSource' => $this->managerStore->activeSource(),
                'statusCheck' => $this->buildUpdateStatusCheck(),
                'availableTags' => $this->availableTags(),
                'fullUpdateEnabled' => (bool) config('updater.full_update.enabled', false),
                'defaultUpdateMode' => $this->defaultUpdateMode(),
            ]),
            'runs' => view('laravel-updater::sections.runs', [
                'runs' => app(UpdaterKernel::class)->stateStore()->recentRuns(100),
            ]),
            'sources' => view('laravel-updater::sections.sources', [
                'sources' => $this->managerStore->sources(),
                'editingSource' => $request->filled('edit') ? $this->managerStore->findSource((int) $request->input('edit')) : null,
                'allowMultipleSources' => (bool) config('updater.sources.allow_multiple', false),
            ]),
            'profiles' => redirect()->route('updater.profiles.index'),
            'backups' => view('laravel-updater::sections.backups', ['backups' => $this->managerStore->backups()]),
            'logs' => view('laravel-updater::sections.logs', [
                'logs' => $this->managerStore->logs(
                    $request->filled('run_id') ? (int) $request->input('run_id') : null,
                    $request->input('level'),
                    $request->input('q')
                ),
            ]),
            'security' => view('laravel-updater::sections.security', [
                'gitSizeBytes' => app(GitMaintenance::class)->sizeBytes(),
                'gitMaintenanceEnabled' => (bool) config('updater.git_maintenance.enabled', true),
                'lockInfo' => app(UpdaterLockTools::class)->info('system-update'),
            ]),
            'migrations' => redirect()->route('updater.migrations.index'),
            'seeds' => redirect()->route('updater.seeds.index'),
            'admin-users' => redirect()->route('updater.users.index'),
            'settings' => redirect()->route('updater.settings.index'),
            default => abort(404),
        };
    }

    public function gitMaintainNow(Request $request): RedirectResponse
    {
        $this->ensureAdmin();

        try {
            app(GitMaintenance::class)->maintain('ui');
        } catch (\Throwable $e) {
            return back()->withErrors(['git' => 'Falha ao executar manutenção do Git: ' . $e->getMessage()]);
        }

        return back()->with('status', 'Manutenção do Git executada com sucesso.');
    }

    public function forceClearUpdateLock(Request $request): RedirectResponse
    {
        $this->ensureAdmin();

        app(UpdaterLockTools::class)->forceClear('system-update');

        return back()->with('status', 'Lock de atualização limpo. Se houver uma execução em andamento, ela poderá falhar.');
    }

    public function usersIndex()
    {
        $this->ensureAdmin();

        return view('laravel-updater::users.index', ['users' => $this->managerStore->users()]);
    }

    public function usersCreate()
    {
        $this->ensureAdmin();

        return view('laravel-updater::users.create', [
            'permissionDefinitions' => $this->permission->definitions(),
            'masterEmail' => (string) config('updater.ui.auth.master_email', ''),
        ]);
    }

    public function usersStore(Request $request): RedirectResponse
    {
        $actor = $this->ensureAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'is_admin' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ], [
            'name.required' => 'Informe o nome.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'password.required' => 'Informe a senha.',
            'password.min' => 'A senha deve ter ao menos 6 caracteres.',
            'password.confirmed' => 'A confirmação de senha não confere.',
        ]);

        $data['is_admin'] = (int) $request->boolean('is_admin');
        $data['is_active'] = (int) $request->boolean('is_active', true);
        $data['permissions_json'] = json_encode($this->permission->normalizePermissions((array) $request->input('permissions', [])), JSON_UNESCAPED_UNICODE);

        $id = $this->managerStore->createUser($data);
        $this->audit($request, $actor['id'], 'Usuário administrativo criado.', ['usuario_id' => $id]);

        return redirect()->route('updater.users.index')->with('status', 'Salvo com sucesso.');
    }

    public function usersEdit(int $id)
    {
        $this->ensureAdmin();
        $user = $this->managerStore->findUser($id);
        abort_if($user === null, 404);

        return view('laravel-updater::users.edit', [
            'user' => $user,
            'permissionDefinitions' => $this->permission->definitions(),
            'masterEmail' => (string) config('updater.ui.auth.master_email', ''),
        ]);
    }

    public function usersUpdate(int $id, Request $request): RedirectResponse
    {
        $actor = $this->ensureAdmin();
        $user = $this->managerStore->findUser($id);
        abort_if($user === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'is_admin' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ], [
            'name.required' => 'Informe o nome.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'password.min' => 'A senha deve ter ao menos 6 caracteres.',
            'password.confirmed' => 'A confirmação de senha não confere.',
        ]);

        $data['is_admin'] = (int) $request->boolean('is_admin');
        $data['is_active'] = (int) $request->boolean('is_active');
        $data['permissions_json'] = json_encode($this->permission->normalizePermissions((array) $request->input('permissions', [])), JSON_UNESCAPED_UNICODE);

        if ((int) $user['is_admin'] === 1 && (int) $user['is_active'] === 1 && ($data['is_admin'] === 0 || $data['is_active'] === 0) && $this->managerStore->activeAdminCount() <= 1) {
            return back()->withErrors(['is_admin' => 'Não é possível remover ou inativar o último admin ativo.']);
        }

        $this->managerStore->updateUser($id, $data);
        $this->audit($request, $actor['id'], 'Usuário administrativo atualizado.', ['usuario_id' => $id]);

        return redirect()->route('updater.users.index')->with('status', 'Salvo com sucesso.');
    }

    public function usersDelete(int $id, Request $request): RedirectResponse
    {
        $actor = $this->ensureAdmin();
        $user = $this->managerStore->findUser($id);
        abort_if($user === null, 404);

        if ((int) $actor['id'] === $id) {
            return back()->withErrors(['user' => 'Você não pode excluir seu próprio usuário.']);
        }

        if ((int) $user['is_admin'] === 1 && (int) $user['is_active'] === 1 && $this->managerStore->activeAdminCount() <= 1) {
            return back()->withErrors(['user' => 'Não é possível excluir o último admin ativo.']);
        }

        $this->managerStore->deleteUser($id);
        $this->audit($request, $actor['id'], 'Usuário administrativo removido.', ['usuario_id' => $id]);

        return redirect()->route('updater.users.index')->with('status', 'Registro removido com sucesso.');
    }

    public function usersResetTwoFactor(int $id, Request $request): RedirectResponse
    {
        $actor = $this->ensureAdmin();
        $validated = $request->validate(['admin_password' => ['required', 'string']], ['admin_password.required' => 'Informe sua senha para confirmar a ação.']);
        $actorRow = $this->managerStore->findUser((int) $actor['id']);

        if ($actorRow === null || !password_verify($validated['admin_password'], (string) $actorRow['password_hash'])) {
            return back()->withErrors(['admin_password' => 'Credenciais inválidas.']);
        }

        $this->managerStore->resetUserTwoFactor($id);
        $this->audit($request, $actor['id'], '2FA de usuário redefinido.', ['usuario_id' => $id]);

        return back()->with('status', 'Salvo com sucesso.');
    }

    public function profilesIndex()
    {
        return view('laravel-updater::profiles.index', ['profiles' => $this->managerStore->profiles()]);
    }

    public function profilesCreate()
    {
        return view('laravel-updater::profiles.create', [
            'profile' => [
                'pre_update_commands' => $this->defaultPreUpdateCommands(),
                'post_update_commands' => $this->defaultPostUpdateCommands(),
                'snapshot_include_vendor' => 0,
                'snapshot_compression' => 'zip',
            ],
        ]);
    }

    public function profilesStore(Request $request): RedirectResponse
    {
        $data = $this->validateProfile($request);
        $this->managerStore->createOrUpdateProfile($data);
        $this->audit($request, $this->actorId($request), 'Perfil de atualização criado.', ['nome' => $data['name']]);

        return redirect()->route('updater.profiles.index')->with('status', 'Salvo com sucesso.');
    }

    public function profilesEdit(int $id)
    {
        $profile = $this->managerStore->findProfile($id);
        abort_if($profile === null, 404);

        $profile['post_update_commands'] = $this->mergePostUpdateSuggestions((string) ($profile['post_update_commands'] ?? ''));

        return view('laravel-updater::profiles.edit', ['profile' => $profile]);
    }

    public function profilesUpdate(int $id, Request $request): RedirectResponse
    {
        $profile = $this->managerStore->findProfile($id);
        abort_if($profile === null, 404);

        $data = $this->validateProfile($request);
        $this->managerStore->createOrUpdateProfile($data, $id);
        $this->audit($request, $this->actorId($request), 'Perfil de atualização atualizado.', ['perfil_id' => $id]);

        return redirect()->route('updater.profiles.index')->with('status', 'Salvo com sucesso.');
    }

    public function profilesDelete(int $id, Request $request): RedirectResponse
    {
        $this->managerStore->deleteProfile($id);
        $this->audit($request, $this->actorId($request), 'Perfil de atualização removido.', ['perfil_id' => $id]);

        return redirect()->route('updater.profiles.index')->with('status', 'Registro removido com sucesso.');
    }

    public function profilesActivate(int $id, Request $request): RedirectResponse
    {
        $this->managerStore->activateProfile($id);
        $this->audit($request, $this->actorId($request), 'Perfil de atualização ativado.', ['perfil_id' => $id]);

        return back()->with('status', 'Salvo com sucesso.');
    }

    public function settingsIndex()
    {
        return view('laravel-updater::settings.index', [
            'branding' => $this->managerStore->resolvedBranding(),
            'tokens' => $this->managerStore->apiTokens(),
            'sources' => $this->managerStore->sources(),
            'activeSource' => $this->managerStore->activeSource(),
            'profiles' => $this->managerStore->profiles(),
            'backupUpload' => $this->managerStore->backupUploadSettings(),
            'updaterConfigFields' => $this->buildUpdaterConfigFields(),
        ]);
    }

    public function settingsEnvIndex()
    {
        return view('laravel-updater::settings.env', [
            'envFields' => $this->buildUpdaterEnvFields(),
        ]);
    }

    public function saveUpdaterConfig(Request $request): RedirectResponse
    {
        $definitions = $this->discoverUpdaterConfigDefinitions();
        $envKeys = $this->readDotEnvKeys();
        $stored = (array) $this->managerStore->getRuntimeOption('updater_config_overrides', []);

        foreach ($definitions as $item) {
            $field = (string) ($item['field'] ?? '');
            $type = (string) ($item['type'] ?? 'string');
            $configKey = (string) ($item['config'] ?? '');
            $envKeysForField = (array) ($item['env_keys'] ?? []);
            $isSensitive = (bool) ($item['sensitive'] ?? false);

            if ($field === '' || $configKey === '') {
                continue;
            }

            // Se está fixo no .env, não permite override pela UI.
            $envLocked = false;
            foreach ($envKeysForField as $envKey) {
                if (isset($envKeys[(string) $envKey])) {
                    $envLocked = true;
                    break;
                }
            }

            if ($envLocked) {
                continue;
            }

            if ($type === 'bool') {
                $stored[$configKey] = $request->boolean($field);
                continue;
            }

            $value = trim((string) $request->input($field, ''));
            if ($type === 'int') {
                $stored[$configKey] = max(0, (int) $value);
                continue;
            }

            if ($type === 'list') {
                $lines = preg_split('/\r?\n/', $value) ?: [];
                $stored[$configKey] = array_values(array_filter(array_map(static fn ($line) => trim((string) $line), $lines), static fn ($line) => $line !== ''));
                continue;
            }

            if ($isSensitive && $value === '') {
                // Mantém valor anterior para evitar apagar segredo por envio de campo vazio.
                continue;
            }

            $stored[$configKey] = $value;
        }

        $this->managerStore->setRuntimeOption('updater_config_overrides', $stored);
        $this->audit($request, $this->actorId($request), 'Configurações gerais do updater atualizadas pela UI.');

        return back()->with('status', 'Configurações do updater salvas com sucesso.');
    }

    public function saveUpdaterEnv(Request $request): RedirectResponse
    {
        $fields = $this->buildUpdaterEnvFields();
        $updates = [];

        foreach ($fields as $field) {
            $input = (string) ($field['field'] ?? '');
            $envKey = (string) ($field['primary_env_key'] ?? '');
            $type = (string) ($field['type'] ?? 'string');
            $sensitive = (bool) ($field['sensitive'] ?? false);

            if ($input === '' || $envKey === '') {
                continue;
            }

            if ($type === 'bool') {
                $updates[$envKey] = $request->boolean($input) ? 'true' : 'false';
                continue;
            }

            $raw = (string) $request->input($input, '');
            if ($sensitive && trim($raw) === '') {
                continue;
            }

            if ($type === 'list') {
                $lines = preg_split('/\r?\n/', $raw) ?: [];
                $lines = array_values(array_filter(array_map(static fn ($line) => trim((string) $line), $lines), static fn ($line) => $line !== ''));
                $updates[$envKey] = implode(',', $lines);
                continue;
            }

            $updates[$envKey] = trim($raw);
        }

        $this->writeDotEnvEntries($updates);
        $this->audit($request, $this->actorId($request), 'Parâmetros .env do updater atualizados pela UI.', ['keys' => array_keys($updates)]);

        return back()->with('status', 'Parâmetros .env salvos. Execute php artisan config:clear para aplicar imediatamente.');
    }

    public function saveBranding(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:120'],
            'app_sufix_name' => ['nullable', 'string', 'max:120'],
            'app_desc' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'maintenance_title' => ['nullable', 'string', 'max:120'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'maintenance_footer' => ['nullable', 'string', 'max:200'],
            'first_run_assume_behind' => ['nullable', 'boolean'],
            'first_run_assume_behind_commits' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'enter_maintenance_on_update_start' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:png,jpg,jpeg,svg'],
            'favicon' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:ico,png'],
            'maintenance_logo' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:png,jpg,jpeg,svg'],
        ]);

        $row = $this->managerStore->branding() ?? [];
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('updater/branding');
        } else {
            $data['logo_path'] = $row['logo_path'] ?? null;
        }

        if ($request->hasFile('favicon')) {
            $data['favicon_path'] = $request->file('favicon')->store('updater/branding');
        } else {
            $data['favicon_path'] = $row['favicon_path'] ?? null;
        }

        if ($request->hasFile('maintenance_logo')) {
            $data['maintenance_logo_path'] = $request->file('maintenance_logo')->store('updater/branding');
        } else {
            $data['maintenance_logo_path'] = $row['maintenance_logo_path'] ?? null;
        }

        $data['first_run_assume_behind'] = (int) $request->boolean('first_run_assume_behind');
        $data['first_run_assume_behind_commits'] = max(1, (int) ($data['first_run_assume_behind_commits'] ?? 1));
        $data['enter_maintenance_on_update_start'] = (int) $request->boolean('enter_maintenance_on_update_start', true);

        $this->managerStore->saveBranding($data);
        $this->audit($request, $this->actorId($request), 'Branding atualizado.', ['tem_logo' => !empty($data['logo_path']), 'tem_favicon' => !empty($data['favicon_path'])]);

        return back()->with('status', 'Salvo com sucesso.');
    }

    public function removeBrandingAsset(string $asset, Request $request): RedirectResponse
    {
        $row = $this->managerStore->branding() ?? [];
        if ($asset === 'logo' && !empty($row['logo_path'])) {
            Storage::delete((string) $row['logo_path']);
            $row['logo_path'] = null;
        }

        if ($asset === 'favicon' && !empty($row['favicon_path'])) {
            Storage::delete((string) $row['favicon_path']);
            $row['favicon_path'] = null;
        }

        if ($asset === 'maintenance-logo' && !empty($row['maintenance_logo_path'])) {
            Storage::delete((string) $row['maintenance_logo_path']);
            $row['maintenance_logo_path'] = null;
        }

        $this->managerStore->saveBranding($row);
        $this->audit($request, $this->actorId($request), 'Arquivo de branding removido.', ['asset' => $asset]);

        return back()->with('status', 'Registro removido com sucesso.');
    }

    public function resetBranding(Request $request): RedirectResponse
    {
        $this->managerStore->resetBrandingToEnv();
        $this->audit($request, $this->actorId($request), 'Branding resetado para ENV.');

        return back()->with('status', 'Salvo com sucesso.');
    }

    public function saveSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:github,gitlab,bitbucket,git,zip,git_ff_only,git_merge,git_tag,zip_release'],
            'repo_url' => ['required', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:120'],
            'auth_mode' => ['required', 'in:token,ssh,none'],
            'auth_username' => ['nullable', 'string', 'max:120'],
            'auth_password' => ['nullable', 'string', 'max:255'],
            'token_encrypted' => ['nullable', 'string', 'max:255'],
            'ssh_private_key_path' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'post_update_commands' => ['nullable', 'string', 'max:8000'],
        ]);

        $data['type'] = match ((string) $data['type']) {
            'git' => 'git_merge',
            'zip' => 'zip_release',
            default => (string) $data['type'],
        };

        if (!empty($data['auth_password']) && empty($data['token_encrypted'])) {
            $data['token_encrypted'] = $data['auth_password'];
        }

        $id = isset($data['id']) ? (int) $data['id'] : null;
        $allowMultipleSources = (bool) config('updater.sources.allow_multiple', false);
        if (!$allowMultipleSources && $id === null && count($this->managerStore->sources()) > 0) {
            return back()->withErrors(['source' => 'Cadastro de múltiplas fontes está bloqueado. Para habilitar, defina UPDATER_SOURCES_ALLOW_MULTIPLE=true.'])->withInput();
        }

        if (!$allowMultipleSources) {
            $data['active'] = 1;
        }

        $this->managerStore->createOrUpdateSource($data, $id);
        $this->audit($request, $this->actorId($request), 'Fonte de atualização salva.', ['fonte_id' => $id]);

        return back()->with('status', 'Salvo com sucesso.');
    }

    public function activateSource(int $id, Request $request): RedirectResponse
    {
        $this->managerStore->setActiveSource($id);
        $this->audit($request, $this->actorId($request), 'Fonte ativa alterada.', ['fonte_id' => $id]);

        return back()->with('status', 'Salvo com sucesso.');
    }

    public function deleteSource(int $id, Request $request): RedirectResponse
    {
        $this->managerStore->deleteSource($id);
        $this->audit($request, $this->actorId($request), 'Fonte de atualização removida.', ['fonte_id' => $id]);

        return back()->with('status', 'Registro removido com sucesso.');
    }

    public function testSourceConnection(Request $request, ShellRunner $shellRunner): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['nullable', 'integer'],
        ]);

        $source = null;
        if (!empty($data['source_id'])) {
            $source = $this->managerStore->findSource((int) $data['source_id']);
        }

        if ($source === null) {
            $source = $this->managerStore->activeSource();
        }

        if ($source === null) {
            return back()->withErrors(['source' => 'Nenhuma fonte selecionada/ativa para testar.']);
        }

        $repoUrl = $this->buildAuthRepoUrl($source);
        if ($repoUrl === '') {
            return back()->withErrors(['source' => 'A fonte não possui URL de repositório válida.']);
        }

        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        $head = $shellRunner->run(['git', 'ls-remote', '--heads', $repoUrl], null, $env);
        $tags = $shellRunner->run(['git', 'ls-remote', '--tags', '--refs', $repoUrl], null, $env);

        if ($head['exit_code'] !== 0 && $tags['exit_code'] !== 0) {
            return back()->withErrors(['source' => 'Falha ao conectar com a fonte: ' . ($head['stderr'] ?: $tags['stderr'] ?: 'erro desconhecido')]);
        }

        $versions = $this->parseRemoteVersions((string) ($tags['stdout'] ?? ''));
        $preview = $versions !== [] ? implode(', ', array_slice($versions, 0, 10)) : 'Sem tags encontradas';

        return back()->with('status', 'Conexão validada com sucesso. Versões encontradas: ' . $preview);
    }

    public function createApiToken(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => 'Informe um nome para o token.']);
        $token = $this->managerStore->generateApiToken($data['name']);
        $this->audit($request, $this->actorId($request), 'Token de API criado.', ['token_id' => $token['id']]);

        return back()->with('status', 'Salvo com sucesso.')->with('token_plain', $token['token']);
    }

    public function revokeApiToken(int $id, Request $request): RedirectResponse
    {
        $this->managerStore->revokeApiToken($id);
        $this->audit($request, $this->actorId($request), 'Token de API revogado.', ['token_id' => $id]);

        return back()->with('status', 'Registro removido com sucesso.');
    }


    public function saveBackupUploadSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:none,dropbox,google-drive,s3,minio'],
            'prefix' => ['nullable', 'string', 'max:190'],
            'auto_upload' => ['nullable', 'boolean'],
            'dropbox_access_token' => ['nullable', 'string', 'max:500'],
            'google_client_id' => ['nullable', 'string', 'max:255'],
            'google_client_secret' => ['nullable', 'string', 'max:255'],
            'google_refresh_token' => ['nullable', 'string', 'max:500'],
            'google_folder_id' => ['nullable', 'string', 'max:255'],
            's3_endpoint' => ['nullable', 'string', 'max:255'],
            's3_region' => ['nullable', 'string', 'max:120'],
            's3_bucket' => ['nullable', 'string', 'max:255'],
            's3_access_key' => ['nullable', 'string', 'max:255'],
            's3_secret_key' => ['nullable', 'string', 'max:255'],
            's3_path_style' => ['nullable', 'boolean'],
        ]);

        $this->managerStore->saveBackupUploadSettings([
            'provider' => $data['provider'],
            'prefix' => (string) ($data['prefix'] ?? 'updater/backups'),
            'auto_upload' => $request->boolean('auto_upload'),
            'dropbox' => [
                'access_token' => (string) ($data['dropbox_access_token'] ?? ''),
            ],
            'google_drive' => [
                'client_id' => (string) ($data['google_client_id'] ?? ''),
                'client_secret' => (string) ($data['google_client_secret'] ?? ''),
                'refresh_token' => (string) ($data['google_refresh_token'] ?? ''),
                'folder_id' => (string) ($data['google_folder_id'] ?? ''),
            ],
            's3' => [
                'endpoint' => (string) ($data['s3_endpoint'] ?? ''),
                'region' => (string) ($data['s3_region'] ?? 'us-east-1'),
                'bucket' => (string) ($data['s3_bucket'] ?? ''),
                'access_key' => (string) ($data['s3_access_key'] ?? ''),
                'secret_key' => (string) ($data['s3_secret_key'] ?? ''),
                'path_style' => $request->boolean('s3_path_style', true),
            ],
        ]);

        $this->audit($request, $this->actorId($request), 'Configuração de upload de backups atualizada.', [
            'provider' => $data['provider'],
            'auto_upload' => $request->boolean('auto_upload'),
        ]);

        return back()->with('status', 'Configuração de upload salva com sucesso.');
    }

    private function validateProfile(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'retention_backups' => ['nullable', 'integer', 'min:1', 'max:200'],
            'backup_enabled' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            'composer_install' => ['nullable', 'boolean'],
            'migrate' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'boolean'],
            'rollback_on_fail' => ['nullable', 'boolean'],
            'snapshot_include_vendor' => ['nullable', 'boolean'],
            'snapshot_compression' => ['nullable', 'in:zip,auto,7z,tgz'],
            'active' => ['nullable', 'boolean'],
            'pre_update_commands' => ['nullable', 'string', 'max:8000'],
            'post_update_commands' => ['nullable', 'string', 'max:8000'],
        ], [
            'name.required' => 'Informe o nome do perfil.',
            'retention_backups.integer' => 'A retenção deve ser numérica.',
        ]);

        $toggles = ['backup_enabled', 'dry_run', 'force', 'composer_install', 'migrate', 'seed', 'rollback_on_fail', 'snapshot_include_vendor', 'active'];
        foreach ($toggles as $toggle) {
            $data[$toggle] = (int) $request->boolean($toggle);
        }

        $data['pre_update_commands'] = trim((string) ($data['pre_update_commands'] ?? ''));
        if ($data['pre_update_commands'] === '') {
            $data['pre_update_commands'] = null;
        }

        $data['post_update_commands'] = $this->mergePostUpdateSuggestions(trim((string) ($data['post_update_commands'] ?? '')));
        $data['snapshot_compression'] = 'zip';

        return $data;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildUpdaterConfigFields(): array
    {
        $envKeys = $this->readDotEnvKeys();
        $overrides = (array) $this->managerStore->getRuntimeOption('updater_config_overrides', []);
        $definitions = $this->discoverUpdaterConfigDefinitions();
        $rows = [];

        foreach ($definitions as $item) {
            $configKey = (string) ($item['config'] ?? '');
            $envKeysForField = (array) ($item['env_keys'] ?? []);
            $type = (string) ($item['type'] ?? 'string');

            $envLocked = false;
            foreach ($envKeysForField as $envKey) {
                if (isset($envKeys[(string) $envKey])) {
                    $envLocked = true;
                    break;
                }
            }

            $value = $envLocked
                ? config($configKey, $item['default'] ?? null)
                : ($overrides[$configKey] ?? config($configKey, $item['default'] ?? null));

            $rows[] = array_merge($item, [
                'env_locked' => $envLocked,
                'value' => $this->normalizeFieldValue($value, $type),
                'env_key' => implode(' | ', $envKeysForField),
                'primary_env_key' => (string) ($envKeysForField[0] ?? ''),
            ]);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['config'], (string) $b['config']));

        return $rows;
    }

    /**
     * @return array<string,bool>
     */
    private function readDotEnvKeys(): array
    {
        $path = function_exists('base_path') ? base_path('.env') : '.env';
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return [];
        }

        $keys = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([A-Z0-9_]+)\s*=/', $line, $m) === 1) {
                $keys[$m[1]] = true;
            }
        }

        return $keys;
    }

    private function normalizeFieldValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'list' => is_array($value) ? implode("\n", array_map(static fn ($v) => (string) $v, $value)) : (string) $value,
            default => (string) ($value ?? ''),
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function discoverUpdaterConfigDefinitions(): array
    {
        $config = (array) config('updater', []);
        $defs = [];
        $this->flattenUpdaterConfig('updater', $config, $defs);

        return $defs;
    }

    /**
     * @param array<int,array<string,mixed>> $defs
     * @param array<string,mixed> $value
     */
    private function flattenUpdaterConfig(string $prefix, array $value, array &$defs): void
    {
        foreach ($value as $key => $current) {
            $keyString = (string) $key;
            $path = $prefix . '.' . $keyString;

            if (is_array($current)) {
                if ($current === []) {
                    continue;
                }

                if ($this->isListOfScalars($current)) {
                    $defs[] = $this->buildConfigDefinition($path, $current, 'list');
                    continue;
                }

                $this->flattenUpdaterConfig($path, $current, $defs);
                continue;
            }

            if (is_bool($current)) {
                $defs[] = $this->buildConfigDefinition($path, $current, 'bool');
                continue;
            }

            if (is_int($current)) {
                $defs[] = $this->buildConfigDefinition($path, $current, 'int');
                continue;
            }

            if (is_float($current)) {
                $defs[] = $this->buildConfigDefinition($path, (string) $current, 'string');
                continue;
            }

            if (is_string($current) || $current === null) {
                $defs[] = $this->buildConfigDefinition($path, (string) ($current ?? ''), 'string');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildConfigDefinition(string $configKey, mixed $default, string $type): array
    {
        $field = str_replace(['.', '-'], '_', $configKey);
        $segments = explode('.', $configKey);
        $group = $segments[1] ?? 'geral';
        $label = ucwords(str_replace('_', ' ', (string) end($segments)));
        $sensitive = preg_match('/(password|token|secret|private|key)/i', $configKey) === 1;

        return [
            'group' => ucfirst($group),
            'label' => $label,
            'config' => $configKey,
            'field' => $field,
            'type' => $type,
            'default' => $default,
            'sensitive' => $sensitive,
            'env_keys' => $this->envKeysForConfigKey($configKey),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function envKeysForConfigKey(string $configKey): array
    {
        $suffix = strtoupper(str_replace('.', '_', preg_replace('/^updater\./', '', $configKey) ?: $configKey));
        $keys = ['UPDATER_' . $suffix];

        // Compatibilidades legadas conhecidas.
        if ($configKey === 'updater.maintenance.render_view') {
            $keys[] = 'UPDATER_MAINTENANCE_RENDER_VIEW';
            $keys[] = 'UPDATER_MAINTENANCE_VIEW';
        }

        if ($configKey === 'updater.ui.auth.rate_limit.max_attempts') {
            $keys[] = 'UPDATER_UI_LOGIN_MAX_ATTEMPTS';
            $keys[] = 'UPDATER_UI_RATE_LIMIT_MAX';
        }

        if ($configKey === 'updater.ui.auth.rate_limit.window_seconds') {
            $keys[] = 'UPDATER_UI_LOGIN_DECAY_MINUTES';
            $keys[] = 'UPDATER_UI_RATE_LIMIT_WINDOW';
        }

        return array_values(array_unique(array_filter($keys, static fn ($k) => trim((string) $k) !== '')));
    }

    /**
     * @param array<int,mixed> $value
     */
    private function isListOfScalars(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildUpdaterEnvFields(): array
    {
        $fields = $this->buildUpdaterConfigFields();
        $envMap = $this->readDotEnvMap();
        $rows = [];

        foreach ($fields as $field) {
            $primary = (string) ($field['primary_env_key'] ?? '');
            if ($primary === '') {
                continue;
            }

            $field['env_value'] = $envMap[$primary] ?? '';
            $rows[] = $field;
        }

        return $rows;
    }

    /**
     * @return array<string,string>
     */
    private function readDotEnvMap(): array
    {
        $path = function_exists('base_path') ? base_path('.env') : '.env';
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $map[$m[1]] = trim((string) $m[2], "\"'");
        }

        return $map;
    }

    /**
     * @param array<string,string> $updates
     */
    private function writeDotEnvEntries(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $path = function_exists('base_path') ? base_path('.env') : '.env';
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = preg_split('/\r?\n/', $content) ?: [];

        $found = [];
        foreach ($lines as $idx => $line) {
            $raw = (string) $line;
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $raw, $m) !== 1) {
                continue;
            }

            $key = (string) $m[1];
            if (!array_key_exists($key, $updates)) {
                continue;
            }

            $lines[$idx] = $key . '=' . $this->normalizeEnvValue($updates[$key]);
            $found[$key] = true;
        }

        foreach ($updates as $key => $value) {
            if (isset($found[$key])) {
                continue;
            }
            $lines[] = $key . '=' . $this->normalizeEnvValue($value);
        }

        file_put_contents($path, implode(PHP_EOL, $lines));
    }

    private function normalizeEnvValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#/', $value) === 1) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }



    private function buildAuthRepoUrl(array $source): string
    {
        $repoUrl = trim((string) ($source['repo_url'] ?? ''));
        if ($repoUrl === '') {
            return '';
        }

        $authMode = (string) ($source['auth_mode'] ?? 'none');
        $username = trim((string) ($source['auth_username'] ?? ''));
        $password = trim((string) ($source['auth_password'] ?? $source['token_encrypted'] ?? ''));

        if (!str_starts_with($repoUrl, 'https://')) {
            return $repoUrl;
        }

        if ($authMode === 'token' && $password !== '') {
            if ($username !== '') {
                return preg_replace('#^https://#', 'https://' . rawurlencode($username) . ':' . rawurlencode($password) . '@', $repoUrl) ?: $repoUrl;
            }

            return preg_replace('#^https://#', 'https://' . rawurlencode($password) . '@', $repoUrl) ?: $repoUrl;
        }

        if ($authMode === 'ssh') {
            return $repoUrl;
        }

        return $repoUrl;
    }

    private function defaultPreUpdateCommands(): string
    {
        return implode("\n", [
            '# php artisan optimize:clear',
            '# php artisan config:clear',
        ]);
    }

    private function defaultPostUpdateCommands(): string
    {
        return implode("\n", $this->suggestedPostUpdateCommands());
    }

    /** @return array<int, string> */
    private function suggestedPostUpdateCommands(): array
    {
        return [
            '#composer require argws/laravel-updater',
            '#php artisan vendor:publish --tag=updater-config --force',
            '#php artisan vendor:publish --tag=updater-views --force',
            '#composer update',
            '#php artisan migrate:rollback --step=20 --force',
            '#php artisan migrate --force',
            '#php artisan db:seed --class=ReformaTributariaSeeder --force',
            '#php artisan cache:clear',
            '#php artisan config:clear',
            '#php artisan route:clear',
            '#php artisan view:clear',
            '#php artisan key:generate --force',
        ];
    }

    private function mergePostUpdateSuggestions(string $raw): string
    {
        $raw = trim($raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        $existing = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $existing[] = $line;
        }

        $normalized = [];
        foreach ($existing as $line) {
            $normalized[] = $this->normalizeCommandLine($line);
        }

        foreach ($this->suggestedPostUpdateCommands() as $suggestion) {
            if (!in_array($this->normalizeCommandLine($suggestion), $normalized, true)) {
                $existing[] = $suggestion;
                $normalized[] = $this->normalizeCommandLine($suggestion);
            }
        }

        return implode("\n", $existing);
    }

    private function normalizeCommandLine(string $line): string
    {
        $line = trim($line);
        if (str_starts_with($line, '#')) {
            $line = ltrim(substr($line, 1));
        }

        return mb_strtolower($line);
    }

    /** @return array<int,string> */
    private function parseRemoteVersions(string $stdout): array
    {
        $tags = [];
        foreach (explode("\n", $stdout) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }

            $ref = (string) $parts[1];
            if (!str_starts_with($ref, 'refs/tags/')) {
                continue;
            }

            $tags[] = str_replace('refs/tags/', '', $ref);
        }

        usort($tags, static fn (string $a, string $b): int => version_compare($b, $a));

        return array_values(array_unique($tags));
    }

    private function buildUpdateStatusCheck(): array
    {
        try {
            $status = app(UpdaterKernel::class)->check(false);
            if (($status['current_revision'] ?? 'N/A') === 'N/A') {
                /** @var ShellRunner $shell */
                $shell = app(ShellRunner::class);
                $git = $shell->run(['git', 'rev-parse', 'HEAD'], base_path());
                if ((int) ($git['exit_code'] ?? 1) === 0 && trim((string) ($git['stdout'] ?? '')) !== '') {
                    $status['current_revision'] = trim((string) $git['stdout']);
                }
            }

            if (($status['current_revision'] ?? 'N/A') === 'N/A') {
                $last = app(UpdaterKernel::class)->stateStore()->lastRun() ?? [];
                $fallbackRevision = (string) ($last['revision_after'] ?? $last['revision_before'] ?? '');
                if ($fallbackRevision !== '') {
                    $status['current_revision'] = $fallbackRevision;
                }
            }

            $status['latest_tag_release_notes_url'] = $this->releaseNotesResolver->resolve((string) ($this->managerStore->activeSource()['repo_url'] ?? ''), (string) ($status['latest_tag'] ?? ''));

            return $status;
        } catch (\Throwable $e) {
            return [
                'current_revision' => 'N/A',
                'remote' => 'N/A',
                'behind_by_commits' => 0,
                'has_updates' => false,
                'latest_tag' => null,
                'has_update_by_tag' => false,
                'latest_tag_release_notes_url' => null,
                'warning' => 'Falha ao consultar atualizações: ' . $e->getMessage(),
            ];
        }
    }

    /** @return array<int,string> */
    private function availableTags(): array
    {
        $activeSource = $this->managerStore->activeSource();
        if (is_array($activeSource) && !empty($activeSource['repo_url'])) {
            /** @var ShellRunner $shellRunner */
            $shellRunner = app(ShellRunner::class);
            $repoUrl = $this->buildAuthRepoUrl($activeSource);
            $result = $shellRunner->run(['git', 'ls-remote', '--tags', '--refs', $repoUrl], null, ['GIT_TERMINAL_PROMPT' => '0']);
            if (($result['exit_code'] ?? 1) === 0) {
                $tags = $this->parseRemoteVersions((string) ($result['stdout'] ?? ''));
                if ($tags !== []) {
                    return array_slice($tags, 0, 30);
                }
            }
        }

        $driver = app(CodeDriverInterface::class);
        if (!$driver instanceof GitDriver) {
            return [];
        }

        return $driver->listTags(30);
    }


    private function defaultUpdateMode(): string
    {
        $mode = strtolower(trim((string) config('updater.git.default_update_mode', 'merge')));

        return in_array($mode, ['merge', 'ff-only', 'tag', 'full-update'], true) ? $mode : 'merge';
    }

    private function ensureAdmin(): array
    {
        $user = request()->attributes->get('updater_user');
        if (!is_array($user)) {
            abort(403, 'Acesso negado.');
        }

        if (!$this->permission->has($user, 'users.manage')) {
            abort(403, 'Acesso negado.');
        }

        return $user;
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->attributes->get('updater_user');

        return is_array($user) ? (int) ($user['id'] ?? 0) : null;
    }

    private function audit(Request $request, ?int $userId, string $action, array $meta = []): void
    {
        $this->managerStore->addAuditLog($userId, $action, $meta, $request->ip(), $request->userAgent());
    }
}
