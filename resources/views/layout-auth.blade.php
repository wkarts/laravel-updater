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
<body class="up-auth">
<main class="up-auth-shell">
    <section class="up-auth-card card">
        <div class="up-auth-brand">
            @if(!empty($branding['logo_path'] ?? null))
                <img src="{{ \Argws\LaravelUpdater\Support\UiAssets::brandingLogoUrl() }}" alt="Logo" class="up-auth-logo">
            @else
                <div class="up-auth-logo up-auth-logo-fallback">UP</div>
            @endif
            <div>
                <strong class="up-auth-title-brand">{{ $branding['app_name'] ?? 'Updater' }}</strong>
                <p class="up-auth-subtitle-brand">{{ $branding['app_desc'] ?? 'Área administrativa protegida' }}</p>
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
    </section>
</main>
</body>
</html>
