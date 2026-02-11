<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Updater')</title>
    <link rel="stylesheet" href="{{ asset('vendor/laravel-updater/updater.css') }}">
</head>
<body>
<div class="topbar">
    <strong>Argws Laravel Updater</strong>
    <div>
        @if(config('updater.ui.auth.enabled', false) && request()->attributes->get('updater_user'))
            <a href="{{ route('updater.index') }}">Dashboard</a>
            <a href="{{ route('updater.profile') }}">Perfil</a>
            <form method="POST" action="{{ route('updater.logout') }}" style="display:inline">
                @csrf
                <button class="secondary" type="submit">Sair</button>
            </form>
        @endif
    </div>
</div>

<div class="container">
    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert error">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
