@php($managerStore = app(\Argws\LaravelUpdater\Support\ManagerStore::class))
@php($branding = $branding ?? $managerStore->resolvedBranding())
@php($user = request()->attributes->get('updater_user'))
@php($perm = app(\Argws\LaravelUpdater\Support\UiPermission::class))
@php($panelLogoUrl = !empty($branding['logo_path'] ?? null) ? \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl() : (!empty($branding['logo_url'] ?? null) ? (string) $branding['logo_url'] : null))
@php($panelFaviconUrl = !empty($branding['favicon_path'] ?? null) ? \Argws\LaravelUpdater\Support\UiAssets::faviconUrl() : (!empty($branding['favicon_url'] ?? null) ? (string) $branding['favicon_url'] : null))

@php($backupUpload = $managerStore->backupUploadSettings())
@php($activeProfile = $managerStore->activeProfile())
@php($activeSource = $managerStore->activeSource())
@php($runtime = $managerStore->runtimeSettings())
@php($connectedProvider = (string) ($backupUpload['provider'] ?? 'none'))
@php($autoUpload = (bool) ($backupUpload['auto_upload'] ?? false))
@php($credentialsConfigured = match($connectedProvider) {
    'dropbox' => !empty($backupUpload['dropbox']['access_token']),
    'google-drive' => !empty($backupUpload['google_drive']['client_id']) && !empty($backupUpload['google_drive']['client_secret']) && !empty($backupUpload['google_drive']['refresh_token']),
    's3', 'minio' => !empty($backupUpload['s3']['endpoint']) && !empty($backupUpload['s3']['bucket']) && !empty($backupUpload['s3']['access_key']) && !empty($backupUpload['s3']['secret_key']),
    default => false,
})

@php($updaterInstalled = 'n/d')
@php($appGitHash = trim((string) @shell_exec('git -C ' . escapeshellarg(base_path()) . ' rev-parse --short HEAD 2>/dev/null')))
@php($appGitTag = trim((string) @shell_exec('git -C ' . escapeshellarg(base_path()) . ' describe --tags --abbrev=0 2>/dev/null')))
@php(
    function_exists('class_exists') && class_exists('Composer\\InstalledVersions') ?
        ($updaterInstalled = (\Composer\InstalledVersions::isInstalled('argws/laravel-updater') ? (\Composer\InstalledVersions::getPrettyVersion('argws/laravel-updater') ?: 'n/d') : 'n/d')) :
        null
)
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Updater Manager')</title>
    @if(!empty($panelFaviconUrl))
        <link rel="icon" href="{{ $panelFaviconUrl }}">
    @endif
    <link rel="stylesheet" href="{{ \Argws\LaravelUpdater\Support\UiAssets::cssUrl() }}">
</head>
<body>
<div class="updater-app">
    <aside class="updater-sidebar" data-drawer>
        <div class="sidebar-brand">
            <div class="sidebar-logo-wrap">
                @if(!empty($panelLogoUrl))
                    <img src="{{ $panelLogoUrl }}" alt="Logo" class="sidebar-logo" referrerpolicy="no-referrer">
                @else
                    <div class="sidebar-logo-fallback">UP</div>
                @endif
            </div>
            <div>
                <strong>{{ $branding['app_name'] ?? 'Updater' }} {{ $branding['app_sufix_name'] ?? '' }}</strong>
                <small>{{ $branding['app_desc'] ?? 'Painel de atualização' }}</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            @if(!is_array($user) || $perm->has($user, 'dashboard.view'))
                <a class="{{ request()->routeIs('updater.index') ? 'active' : '' }}" href="{{ route('updater.index') }}">▣ Dashboard</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'updates.view'))
                <a class="{{ request()->route('section') === 'updates' ? 'active' : '' }}" href="{{ route('updater.section', 'updates') }}">↻ Atualizações</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'runs.view'))
                <a class="{{ request()->route('section') === 'runs' ? 'active' : '' }}" href="{{ route('updater.section', 'runs') }}">◷ Execuções</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'sources.manage'))
                <a class="{{ request()->route('section') === 'sources' ? 'active' : '' }}" href="{{ route('updater.section', 'sources') }}">⌁ Fontes</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'profiles.manage'))
                <a class="{{ request()->routeIs('updater.profiles.*') ? 'active' : '' }}" href="{{ route('updater.profiles.index') }}">⚙ Perfis</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'backups.manage'))
                <a class="{{ request()->route('section') === 'backups' ? 'active' : '' }}" href="{{ route('updater.section', 'backups') }}">⛁ Backups</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'logs.view'))
                <a class="{{ request()->route('section') === 'logs' ? 'active' : '' }}" href="{{ route('updater.section', 'logs') }}">☰ Logs</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'users.manage'))
                <a class="{{ request()->routeIs('updater.users.*') ? 'active' : '' }}" href="{{ route('updater.users.index') }}">👤 Usuários</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'settings.manage'))
                <a class="{{ request()->routeIs('updater.settings.*') ? 'active' : '' }}" href="{{ route('updater.settings.index') }}">✦ Configurações</a>
            @endif
        </nav>

        <div class="sidebar-meta-wrap">
            <section class="sidebar-meta-card">
                <h4>Resumo técnico</h4>
                <ul>
                    <li><span>Nuvem conectada</span><strong>{{ $connectedProvider === 'none' ? 'Desabilitada' : strtoupper($connectedProvider) }}</strong></li>
                    <li><span>Credenciais</span><strong>{{ $credentialsConfigured ? 'Configuradas' : 'Incompletas' }}</strong></li>
                    <li><span>Upload automático</span><strong>{{ $autoUpload ? 'Ativo' : 'Inativo' }}</strong></li>
                    <li><span>Fonte ativa</span><strong>{{ $activeSource['name'] ?? 'n/d' }}</strong></li>
                    <li><span>Perfil ativo</span><strong>{{ $activeProfile['name'] ?? 'n/d' }}</strong></li>
                    <li><span>Manutenção no update</span><strong>{{ ((bool) ($runtime['maintenance']['enter_on_update_start'] ?? true)) ? 'Ativa' : 'Inativa' }}</strong></li>
                    <li><span>Status cURL</span><strong>{{ extension_loaded('curl') ? 'OK' : 'Indisponível' }}</strong></li>
                    <li><span>Status OpenSSL</span><strong>{{ extension_loaded('openssl') ? 'OK' : 'Indisponível' }}</strong></li>
                    <li><span>Status ZIP</span><strong>{{ extension_loaded('zip') ? 'OK' : 'Indisponível' }}</strong></li>
                    <li><span>Laravel</span><strong>{{ app()->version() }}</strong></li>
                    <li><span>Updater</span><strong>{{ $updaterInstalled }}</strong></li>
                    <li><span>PHP</span><strong>{{ PHP_VERSION }}</strong></li>
                    <li><span>Tag Git</span><strong>{{ $appGitTag !== '' ? $appGitTag : 'sem tag' }}</strong></li>
                    <li><span>Hash Git</span><strong>{{ $appGitHash !== '' ? $appGitHash : 'n/d' }}</strong></li>
                    <li><span>Usuário</span><strong>{{ is_array($user) ? (($user['name'] ?? '') !== '' ? $user['name'] : ($user['email'] ?? '-')) : '-' }}</strong></li>
                    <li><span>Data/Hora</span><strong id="updater-sidebar-now">{{ now()->format('d/m/Y H:i:s') }}</strong></li>
                </ul>
            </section>
        </div>
    </aside>

    <div class="drawer-backdrop" data-close-drawer></div>

    <main class="updater-main">
        <header class="updater-topbar">
            <div class="topbar-left">
                <button class="icon-btn" type="button" data-toggle-drawer aria-label="Abrir menu">☰</button>
                <div>
                    <h1>{{ $branding['app_name'] ?? 'Updater' }} {{ $branding['app_sufix_name'] ?? '' }}</h1>
                    <p class="muted">@yield('page_title', 'Dashboard')</p>
                </div>
            </div>

            <div class="topbar-actions">
                @if(is_array($user))
                    <span class="badge">{{ ($user['name'] ?? '') !== '' ? $user['name'] : ($user['email'] ?? '-') }}</span>
                    <a class="btn btn-ghost" href="{{ route('updater.profile') }}">Perfil</a>
                    <form method="POST" action="{{ route('updater.logout') }}">@csrf <button class="btn btn-secondary" type="submit">Sair</button></form>
                @endif
            </div>
        </header>

        <section class="updater-content">
            <div class="breadcrumbs">Início / @yield('breadcrumbs', 'Dashboard')</div>

            @if(session('status'))
                <div class="toast-wrap"><div class="toast">{{ session('status') }}</div></div>
            @endif

            @if($errors->any())
                <div class="card card-danger">
                    <strong>Foram encontrados erros:</strong>
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </section>
    </main>
</div>

<script>
window.UPDATER_PREFIX = @json(trim((string) config('updater.ui.prefix', '_updater'), '/'));
window.UPDATER_UPDATE_PROGRESS_URL = @json(\Illuminate\Support\Facades\Route::has('updater.updates.progress.status') ? route('updater.updates.progress.status') : null);
</script>
<script src="{{ \Argws\LaravelUpdater\Support\UiAssets::jsUrl() }}"></script>
</body>
</html>
