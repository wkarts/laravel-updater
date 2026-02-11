@extends('laravel-updater::auth.layout')
@section('title', 'Perfil Updater')
@section('content')
<div class="card" style="max-width:620px;">
    <h2>Perfil de segurança</h2>
    <p class="muted">Gerencie senha e autenticação em 2 fatores.</p>
    @if (session('status')) <p>{{ session('status') }}</p> @endif
    <form method="POST" action="{{ route('updater.profile.update') }}">
        @csrf
        <div class="row" style="flex-direction:column;">
            <label>Nova senha</label>
            <input type="password" name="password" placeholder="Deixe vazio para manter atual">

            <label>
                <input type="checkbox" name="enable_2fa" value="1" {{ ((int)($user['totp_enabled'] ?? 0) === 1) ? 'checked' : '' }}>
                Ativar 2FA TOTP (issuer: {{ $issuer }})
            </label>

            <button type="submit">Salvar perfil</button>
        </div>
    </form>
    <p><a href="{{ route('updater.index') }}">← Voltar ao dashboard</a></p>
</div>
@endsection
