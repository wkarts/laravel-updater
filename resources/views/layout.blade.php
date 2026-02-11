<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Updater Manager')</title>
    @if(!empty($branding['favicon_path'] ?? null))<link rel="icon" href="{{ route('updater.branding.favicon') }}">@endif
    <link rel="stylesheet" href="{{ route('updater.asset.css') }}">
</head>
<body>
@php($branding = $branding ?? ['app_name' => config('updater.app.name','Updater'), 'app_sufix_name' => config('updater.app.sufix_name',''), 'app_desc' => config('updater.app.desc','')])
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <strong>{{ $branding['app_name'] ?? 'Updater' }} {{ $branding['app_sufix_name'] ?? '' }}</strong>
            <small>{{ $branding['app_desc'] ?? '' }}</small>
        </div>
        <nav class="nav" style="margin-top:14px">
            <a href="{{ route('updater.index') }}">Dashboard</a>
            <a href="{{ route('updater.section', 'updates') }}">Updates</a>
            <a href="{{ route('updater.section', 'runs') }}">Runs</a>
            <a href="{{ route('updater.section', 'sources') }}">Sources</a>
            <a href="{{ route('updater.section', 'profiles') }}">Profiles</a>
            <a href="{{ route('updater.section', 'backups') }}">Backups</a>
            <a href="{{ route('updater.section', 'logs') }}">Logs</a>
            <a href="{{ route('updater.section', 'security') }}">Security</a>
            <a href="{{ route('updater.section', 'admin-users') }}">Admin Users</a>
            <a href="{{ route('updater.section', 'settings') }}">Settings</a>
        </nav>
    </aside>

    <main class="content">
        <div class="topbar">
            <div>
                <button class="drawer-btn secondary" data-toggle-drawer>☰</button>
                <strong>@yield('page_title', 'Updater Manager')</strong>
            </div>
            <div>
                <button class="secondary" data-toggle-theme>Tema</button>
                @if(config('updater.ui.auth.enabled', false) && request()->attributes->get('updater_user'))
                    <a href="{{ route('updater.profile') }}">Perfil</a>
                    <form method="POST" action="{{ route('updater.logout') }}" style="display:inline">@csrf <button class="secondary" type="submit">Sair</button></form>
                @endif
            </div>
        </div>

        <div class="container">
            <div class="breadcrumbs">Updater / @yield('page_title', 'Dashboard')</div>
            @if(session('status'))<div class="toast-wrap"><div class="toast">{{ session('status') }}</div></div>@endif
            @if($errors->any())
                <div class="card" style="border-color:#f04438;color:#b42318">
                    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<script src="{{ route('updater.asset.js') }}"></script>
</body>
</html>
