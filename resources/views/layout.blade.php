@php
    $managerStore = app(\Argws\LaravelUpdater\Support\ManagerStore::class);
    $branding = $branding ?? $managerStore->resolvedBranding();
    $user = request()->attributes->get('updater_user');
    $perm = app(\Argws\LaravelUpdater\Support\UiPermission::class);
    $panelLogoUrl = !empty($branding['logo_path'] ?? null)
        ? \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl()
        : (!empty($branding['logo_url'] ?? null) ? (string) $branding['logo_url'] : null);
    $panelFaviconUrl = !empty($branding['favicon_path'] ?? null)
        ? \Argws\LaravelUpdater\Support\UiAssets::faviconUrl()
        : (!empty($branding['favicon_url'] ?? null) ? (string) $branding['favicon_url'] : null);

    // Card lateral: cálculo simples, sem alterar fluxo da aplicação.
    $provider = 'none';
    $autoUpload = false;
    $cloudConnected = false;
    $activeProfileName = 'n/d';
    $activeSourceName = 'n/d';

    $localTag = '';
    $remoteTag = 'n/d';
    $updaterInstalled = 'n/d';
    $updateChannel = (string) config('updater.channel', 'stable');
    $sourceType = 'n/d';
    $sourceOrigin = 'n/d';
    $instanceStatus = (string) (($status['last_run']['status'] ?? '') !== '' ? $status['last_run']['status'] : 'idle');

    try {
        $backupUpload = $managerStore->backupUploadSettings();
        $provider = (string) ($backupUpload['provider'] ?? 'none');
        $autoUpload = (bool) ($backupUpload['auto_upload'] ?? false);

        if ($provider === 'dropbox') {
            $cloudConnected = !empty($backupUpload['dropbox']['access_token']);
        } elseif ($provider === 'google-drive') {
            $cloudConnected = !empty($backupUpload['google_drive']['client_id'])
                && !empty($backupUpload['google_drive']['client_secret'])
                && !empty($backupUpload['google_drive']['refresh_token']);
        } elseif ($provider === 's3' || $provider === 'minio') {
            $cloudConnected = !empty($backupUpload['s3']['endpoint'])
                && !empty($backupUpload['s3']['bucket'])
                && !empty($backupUpload['s3']['access_key'])
                && !empty($backupUpload['s3']['secret_key']);
        }

        $activeProfile = $managerStore->activeProfile();
        $activeSource = $managerStore->activeSource();
        $activeProfileName = (string) ($activeProfile['name'] ?? 'n/d');
        $activeSourceName = (string) ($activeSource['name'] ?? 'n/d');
        $sourceType = (string) ($activeSource['type'] ?? 'n/d');
        $sourceOrigin = (string) ($activeSource['repo_url'] ?? 'n/d');

        if (class_exists('Composer\InstalledVersions')) {
            if (\Composer\InstalledVersions::isInstalled('argws/laravel-updater')) {
                $updaterInstalled = \Composer\InstalledVersions::getPrettyVersion('argws/laravel-updater') ?: 'n/d';
            }
        }

        $localTagOut = @shell_exec('git -C ' . escapeshellarg(base_path()) . ' describe --tags --abbrev=0 2>/dev/null');
        $localTag = trim((string) $localTagOut);

        // Evita bloqueio/timeout de renderização da sidebar por consulta remota.
        // Exibe apenas um indicativo rápido baseado na fonte ativa.
        $remoteTag = trim((string) ($versionBar['updater']['latest'] ?? ($activeSource['branch'] ?? '')));
        if ($remoteTag === '') {
            $remoteTag = 'n/d';
        }
    } catch (\Throwable $e) {
        // Não interrompe a renderização da view principal.
    }

    $cloudLedClass = 'led-off';
    $cloudStatusText = 'desligado';
    if ($provider !== 'none' && $cloudConnected) {
        $cloudLedClass = 'led-ok';
        $cloudStatusText = 'conectado';
    } elseif ($provider !== 'none' && !$cloudConnected) {
        $cloudLedClass = 'led-error';
        $cloudStatusText = 'erro';
    }
@endphp

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
            <a class="{{ request()->routeIs('updater.index') ? 'active' : '' }}" href="{{ route('updater.index') }}">▣ Dashboard</a>
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
            @if(!is_array($user) || $perm->has($user, 'migrations.manage'))
                <a class="{{ request()->routeIs('updater.migrations.*') || request()->route('section') === 'migrations' ? 'active' : '' }}" href="{{ route('updater.migrations.index') }}">🧬 Migrations</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'users.manage'))
                <a class="{{ request()->routeIs('updater.users.*') ? 'active' : '' }}" href="{{ route('updater.users.index') }}">👤 Usuários</a>
            @endif
            @if(!is_array($user) || $perm->has($user, 'settings.manage'))
                <a class="{{ request()->routeIs('updater.settings.*') ? 'active' : '' }}" href="{{ route('updater.settings.index') }}">✦ Configurações</a>
                <a class="{{ request()->route('section') === 'security' ? 'active' : '' }}" href="{{ route('updater.section', 'security') }}">🔒 Segurança</a>
            @endif
        </nav>

        <div class="sidebar-meta-wrap">
            <section class="sidebar-meta-card">
                <h4>Status</h4>
                <ul>
                    <li>
                        <span>Nuvem backup</span>
                        <strong><i class="status-led {{ $cloudLedClass }}"></i>{{ strtoupper($provider === 'none' ? 'desligado' : $provider) }}</strong>
                    </li>
                    <li><span>Conexão</span><strong>{{ $cloudStatusText }}</strong></li>
                    <li><span>Upload auto</span><strong>{{ $autoUpload ? 'ativo' : 'inativo' }}</strong></li>
                    <li><span>Fonte</span><strong>{{ $activeSourceName }}</strong></li>
                    <li><span>Canal</span><strong>{{ $updateChannel }}</strong></li>
                    <li><span>Tipo de origem</span><strong>{{ $sourceType }}</strong></li>
                    <li><span>Perfil</span><strong>{{ $activeProfileName }}</strong></li>
                    <li><span>Updater</span><strong>{{ $updaterInstalled }}</strong></li>
                    <li><span>Tag local</span><strong>{{ $localTag !== '' ? $localTag : 'n/d' }}</strong></li>
                    <li><span>Versão disponível</span><strong>{{ $remoteTag }}</strong></li>
                    <li><span>Instância</span><strong>{{ $instanceStatus }}</strong></li>
                    <li><span>Origem ativa</span><strong title="{{ $sourceOrigin }}">{{ \Illuminate\Support\Str::limit($sourceOrigin, 28) }}</strong></li>
                    <li><span>Usuário</span><strong>{{ is_array($user) ? (($user['name'] ?? '') !== '' ? $user['name'] : ($user['email'] ?? '-')) : '-' }}</strong></li>
                    <li><span>Agora</span><strong id="updater-sidebar-now">{{ now()->format('d/m/Y H:i:s') }}</strong></li>
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
