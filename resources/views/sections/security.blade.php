@extends('laravel-updater::layout')
@section('page_title', 'Segurança')

@section('content')
<div class="grid">
    <div class="card">
        <h3>2FA e recovery</h3>
        <p class="muted">Acesse seu perfil para ativar/desativar 2FA, visualizar QRCode no setup e regenerar recovery codes.</p>
        <a class="btn btn-primary" href="{{ route('updater.profile') }}">Abrir perfil e segurança</a>
    </div>

    <div class="card">
        <h3>Boas práticas</h3>
        <ul>
            <li>Ative 2FA para administradores.</li>
            <li>Guarde os recovery codes em local seguro.</li>
            <li>Revogue tokens e sessões periodicamente.</li>
        </ul>
    </div>
</div>
@endsection
