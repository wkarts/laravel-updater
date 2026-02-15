<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Drivers\GitDriver;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ManagerController extends Controller
{
    public function __construct(private readonly ManagerStore $managerStore)
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
            'security' => view('laravel-updater::sections.security'),
            'seeds' => redirect()->route('updater.seeds.index'),
            'admin-users' => redirect()->route('updater.users.index'),
            'settings' => redirect()->route('updater.settings.index'),
            default => abort(404),
        };
    }

    public function usersIndex()
    {
        $this->ensureAdmin();

        return view('laravel-updater::users.index', ['users' => $this->managerStore->users()]);
    }

    public function usersCreate()
    {
        $this->ensureAdmin();

        return view('laravel-updater::users.create');
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

        $id = $this->managerStore->createUser($data);
        $this->audit($request, $actor['id'], 'Usuário administrativo criado.', ['usuario_id' => $id]);

        return redirect()->route('updater.users.index')->with('status', 'Salvo com sucesso.');
    }

    public function usersEdit(int $id)
    {
        $this->ensureAdmin();
        $user = $this->managerStore->findUser($id);
        abort_if($user === null, 404);

        return view('laravel-updater::users.edit', ['user' => $user]);
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
        ], [
            'name.required' => 'Informe o nome.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'password.min' => 'A senha deve ter ao menos 6 caracteres.',
            'password.confirmed' => 'A confirmação de senha não confere.',
        ]);

        $data['is_admin'] = (int) $request->boolean('is_admin');
        $data['is_active'] = (int) $request->boolean('is_active');

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
        return view('laravel-updater::profiles.create');
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
        ]);
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
            'logo' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:png,jpg,jpeg,svg'],
            'favicon' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:ico,png'],
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
            'active' => ['nullable', 'boolean'],
            'post_update_commands' => ['nullable', 'string', 'max:8000'],
        ], [
            'name.required' => 'Informe o nome do perfil.',
            'retention_backups.integer' => 'A retenção deve ser numérica.',
        ]);

        $toggles = ['backup_enabled', 'dry_run', 'force', 'composer_install', 'migrate', 'seed', 'rollback_on_fail', 'active'];
        foreach ($toggles as $toggle) {
            $data[$toggle] = (int) $request->boolean($toggle);
        }

        $data['post_update_commands'] = trim((string) ($data['post_update_commands'] ?? ''));
        if ($data['post_update_commands'] === '') {
            $data['post_update_commands'] = null;
        }

        return $data;
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

            return $status;
        } catch (\Throwable $e) {
            return [
                'current_revision' => 'N/A',
                'remote' => 'N/A',
                'behind_by_commits' => 0,
                'has_updates' => false,
                'latest_tag' => null,
                'has_update_by_tag' => false,
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
        if (!is_array($user) || !((bool) ($user['is_admin'] ?? false))) {
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