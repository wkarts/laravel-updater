<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
            ]),
            'runs' => view('laravel-updater::sections.runs', [
                'runs' => app('updater.kernel')->stateStore()->recentRuns(100),
            ]),
            'sources' => view('laravel-updater::sections.sources', ['sources' => $this->managerStore->sources()]),
            'profiles' => view('laravel-updater::sections.profiles', ['profiles' => $this->managerStore->profiles()]),
            'backups' => view('laravel-updater::sections.backups', ['backups' => $this->managerStore->backups()]),
            'logs' => view('laravel-updater::sections.logs', [
                'logs' => $this->managerStore->logs(
                    $request->filled('run_id') ? (int) $request->input('run_id') : null,
                    $request->input('level'),
                    $request->input('q')
                ),
            ]),
            'security' => view('laravel-updater::sections.security'),
            'admin-users' => view('laravel-updater::sections.admin-users', [
                'users' => app('updater.store')->pdo()->query('SELECT id,email,name,is_admin,is_active,last_login_at FROM updater_users ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            ]),
            'settings' => view('laravel-updater::sections.settings', [
                'branding' => $this->managerStore->resolvedBranding(),
            ]),
            default => abort(404),
        };
    }

    public function saveBranding(Request $request)
    {
        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:120'],
            'app_sufix_name' => ['nullable', 'string', 'max:120'],
            'app_desc' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:png,jpg,jpeg,svg'],
            'favicon' => ['nullable', 'file', 'max:' . (int) config('updater.branding.max_upload_kb', 1024), 'mimes:ico,png'],
        ]);

        $row = $this->managerStore->branding() ?? [];
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('updater/branding');
            $data['logo_path'] = $path;
        } else {
            $data['logo_path'] = $row['logo_path'] ?? null;
        }

        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store('updater/branding');
            $data['favicon_path'] = $path;
        } else {
            $data['favicon_path'] = $row['favicon_path'] ?? null;
        }

        $this->managerStore->saveBranding($data);

        return back()->with('status', 'Branding salvo com sucesso.');
    }

    public function resetBranding()
    {
        $this->managerStore->resetBrandingToEnv();

        return back()->with('status', 'Branding resetado para ENV.');
    }

    public function saveSource(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:github,gitlab,bitbucket,git,zip'],
            'repo_url' => ['required', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:120'],
            'auth_mode' => ['required', 'in:token,ssh,none'],
            'token_encrypted' => ['nullable', 'string', 'max:255'],
            'ssh_private_key_path' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $id = isset($data['id']) ? (int) $data['id'] : null;
        $this->managerStore->createOrUpdateSource($data, $id);

        return back()->with('status', 'Source salva com sucesso.');
    }

    public function activateSource(int $id)
    {
        $this->managerStore->setActiveSource($id);

        return back()->with('status', 'Source ativa atualizada.');
    }

    public function testSourceConnection(TriggerDispatcher $dispatcher)
    {
        return back()->with('status', 'Teste de conexão executado (simulado). Driver: ' . get_class($dispatcher));
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'retention_backups' => ['nullable', 'integer', 'min:1', 'max:200'],
            'backup_enabled' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            'composer_install' => ['nullable', 'boolean'],
            'migrate' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'boolean'],
            'build_assets' => ['nullable', 'boolean'],
            'health_check' => ['nullable', 'boolean'],
            'rollback_on_fail' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        $id = isset($data['id']) ? (int) $data['id'] : null;
        $this->managerStore->createOrUpdateProfile($data, $id);

        return back()->with('status', 'Profile salvo com sucesso.');
    }
}
