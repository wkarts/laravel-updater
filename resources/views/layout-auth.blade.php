@php($branding = $branding ?? app(\Argws\LaravelUpdater\Support\ManagerStore::class)->resolvedBranding())
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Autenticação') - {{ $branding['app_name'] ?? 'Updater' }}</title>
    @if(!empty($branding['favicon_path'] ?? null))
        <link rel="icon" href="{{ \Argws\LaravelUpdater\Support\UiAssets::faviconUrl() }}">
    @endif
    <link rel="stylesheet" href="{{ \Argws\LaravelUpdater\Support\UiAssets::cssUrl() }}">
</head>
<body class="auth-page">
<div class="auth-shell">
    <section class="auth-showcase" aria-hidden="true">
        <div class="auth-showcase-glow"></div>
        <div class="auth-showcase-content">
            <div class="auth-brand auth-brand-showcase">
                @if(!empty($branding['logo_path'] ?? null))
                    <img src="{{ \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl() }}" alt="Logo" class="sidebar-logo">
                @else
                    <div class="sidebar-logo-fallback">UP</div>
                @endif
                <div>
                    <strong>{{ $branding['app_name'] ?? 'Updater' }} {{ $branding['app_sufix_name'] ?? '' }}</strong>
                    <small>{{ $branding['app_desc'] ?? 'Atualizações seguras e rastreáveis' }}</small>
                </div>
            </div>

            <h1>Painel do Updater Manager</h1>
            <p>Gerencie fontes, perfis e operações com segurança, histórico e controle administrativo em um único lugar.</p>
            <ul>
                <li>Ambiente seguro para operações críticas</li>
                <li>Controle de acesso e autenticação em duas etapas</li>
                <li>Auditoria de ações administrativas</li>
            </ul>
        </div>
    </section>

    <section class="auth-container">
        <div class="auth-card card">
            <div class="auth-brand auth-brand-form">
                @if(!empty($branding['logo_path'] ?? null))
                    <img src="{{ \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl() }}" alt="Logo" class="sidebar-logo">
                @else
                    <div class="sidebar-logo-fallback">UP</div>
                @endif
                <div>
                    <strong>{{ $branding['app_name'] ?? 'Updater' }}</strong>
                    <small>Área restrita</small>
                </div>
            </div>

            @if($errors->any())
                <div class="auth-alert auth-alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @yield('content')
        </div>
    </section>
</div>
</body>
</html>
