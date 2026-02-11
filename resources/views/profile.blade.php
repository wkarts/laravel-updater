@extends('laravel-updater::layout')

@section('title', 'Perfil Updater')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Perfil</h3>
        <p><strong>E-mail:</strong> {{ $user['email'] }}</p>
        <p><strong>2FA ativo:</strong> {{ !empty($user['totp_enabled']) ? 'Sim' : 'Não' }}</p>
    </div>

    <div class="card">
        <h3>Trocar senha</h3>
        <form method="POST" action="{{ route('updater.profile.password') }}">
            @csrf
            <label for="password">Nova senha</label>
            <input id="password" type="password" name="password" required>
            <label for="password_confirmation" style="margin-top:10px;">Confirmar senha</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required>
            <div style="margin-top:14px;"><button type="submit">Salvar senha</button></div>
        </form>
    </div>
</div>

<div class="card">
    <h3>2FA (TOTP)</h3>
    <p class="muted">Adicione o segredo no autenticador e confirme com um código.</p>
    <label>Secret</label>
    <code>{{ $pendingTotpSecret }}</code>
    <p class="muted">URI:</p>
    <code>{{ $otpauthUri }}</code>

    <form method="POST" action="{{ route('updater.profile.2fa.enable') }}" style="margin-top:12px;">
        @csrf
        <label for="code">Código para ativar 2FA</label>
        <input id="code" type="text" name="code" required>
        <div style="margin-top:14px;"><button type="submit">Ativar 2FA</button></div>
    </form>

    @if(!empty($user['totp_enabled']))
        <form method="POST" action="{{ route('updater.profile.2fa.disable') }}" style="margin-top:10px;">
            @csrf
            <button class="danger" type="submit">Desativar 2FA</button>
        </form>
    @endif
</div>
@endsection
