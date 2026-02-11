@php(
    $branding = $branding ?? [
        'app_name' => config('updater.app.name', 'Updater'),
        'app_sufix_name' => config('updater.app.sufix_name', ''),
        'app_desc' => config('updater.app.desc', ''),
    ]
)
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Updater Manager')</title>
    @if(!empty($branding['favicon_path'] ?? null))
        <link rel="icon" href="{{ \Argws\LaravelUpdater\Support\UiAssets::faviconUrl() }}">
    @endif
    <link rel="stylesheet" href="{{ \Argws\LaravelUpdater\Support\UiAssets::cssUrl() }}">
</head>
<body>

<div class="updater-app">
    <aside class="updater-sidebar" data-drawer>
        <div class="sidebar-brand">
            <div class="sidebar-logo-wrap">
                @if(!empty($branding['logo_path'] ?? null))
                    <img src="{{ \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl() }}" alt="Logo" class="sidebar-logo">
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
            <a class="{{ request()->routeIs('updater.index') ? 'active' : '' }}" href="{{ route('updater.index') }}">Dashboard</a>
            <a class="{{ request()->route('section') === 'updates' ? 'active' : '' }}" href="{{ route('updater.section', 'updates') }}">Updates</a>
            <a class="{{ request()->route('section') === 'runs' ? 'active' : '' }}" href="{{ route('updater.section', 'runs') }}">Runs</a>
            <a class="{{ request()->route('section') === 'sources' ? 'active' : '' }}" href="{{ route('updater.section', 'sources') }}">Sources</a>
            <a class="{{ request()->route('section') === 'profiles' ? 'active' : '' }}" href="{{ route('updater.section', 'profiles') }}">Profiles</a>
            <a class="{{ request()->route('section') === 'backups' ? 'active' : '' }}" href="{{ route('updater.section', 'backups') }}">Backups</a>
            <a class="{{ request()->route('section') === 'logs' ? 'active' : '' }}" href="{{ route('updater.section', 'logs') }}">Logs</a>
            <a class="{{ request()->route('section') === 'security' ? 'active' : '' }}" href="{{ route('updater.section', 'security') }}">Security</a>
            <a class="{{ request()->route('section') === 'admin-users' ? 'active' : '' }}" href="{{ route('updater.section', 'admin-users') }}">Admin Users</a>
            <a class="{{ request()->route('section') === 'settings' ? 'active' : '' }}" href="{{ route('updater.section', 'settings') }}">Settings</a>
        </nav>
    </aside>

    <div class="drawer-backdrop" data-close-drawer></div>

    <main class="updater-main">
        <header class="updater-topbar">
            <div class="topbar-left">
                <button class="icon-btn" type="button" data-toggle-drawer aria-label="Abrir menu">☰</button>
                <div>
                    <h1>@yield('page_title', 'Updater Manager')</h1>
                    <p class="muted">Gerenciamento de atualizações com segurança e rastreabilidade.</p>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="btn btn-secondary" type="button" data-toggle-theme>Tema</button>

                @if(config('updater.ui.auth.enabled', false) && request()->attributes->get('updater_user'))
                    <a class="btn btn-ghost" href="{{ route('updater.profile') }}">Perfil</a>
                    <form method="POST" action="{{ route('updater.logout') }}">@csrf <button class="btn btn-secondary" type="submit">Sair</button></form>
                @endif
            </div>
        </header>

        <section class="updater-content">
            <div class="breadcrumbs">Updater / @yield('page_title', 'Dashboard')</div>

            @if(session('status'))
                <div class="toast-wrap">
                    <div class="toast">{{ session('status') }}</div>
                </div>
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

<script src="{{ \Argws\LaravelUpdater\Support\UiAssets::jsUrl() }}"></script>
</body>
</html>
