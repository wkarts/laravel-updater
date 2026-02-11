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
<div class="auth-container">
    <div class="auth-card card">
        <div class="auth-brand">
            @if(!empty($branding['logo_path'] ?? null))
                <img src="{{ \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl() }}" alt="Logo" class="sidebar-logo">
            @else
                <div class="sidebar-logo-fallback">UP</div>
            @endif
            <div>
                <strong>{{ $branding['app_name'] ?? 'Updater' }} {{ $branding['app_sufix_name'] ?? '' }}</strong>
                <small>{{ $branding['app_desc'] ?? 'Área segura' }}</small>
            </div>
        </div>
        @if($errors->any())
            <div class="card card-danger" style="margin: 12px 0;">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </div>
</div>
</body>
</html>
