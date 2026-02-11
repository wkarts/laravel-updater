@extends('laravel-updater::layout')
@section('page_title', 'Security')

@section('content')
<div class="grid">
    <div class="card">
        <h3>2FA e sessão</h3>
        <p class="muted">Gerencie autenticação de dois fatores, sessão atual e segurança de acesso do painel.</p>
        <a class="btn btn-primary" href="{{ route('updater.profile') }}">Abrir perfil e 2FA</a>
    </div>

    <div class="card">
        <h3>Boas práticas</h3>
        <ul>
            <li>Ative 2FA para administradores.</li>
            <li>Use token de sincronização para integração externa.</li>
            <li>Revogue sessões e tokens periodicamente.</li>
        </ul>
    </div>
</div>
@endsection
