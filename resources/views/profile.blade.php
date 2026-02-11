@extends('laravel-updater::layout')

@section('title', 'Meu perfil')
@section('page_title', 'Meu perfil')
@section('breadcrumbs', 'Perfil')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Perfil</h3>
        <p><strong>E-mail:</strong> {{ $user['email'] }}</p>
        <p><strong>2FA ativo:</strong> {{ !empty($user['totp_enabled']) ? 'Sim' : 'Não' }}</p>
    </div>

    <div class="card">
        <h3>Trocar senha</h3>
        <form method="POST" action="{{ route('updater.profile.password') }}" class="form-grid">
            @csrf
            <div>
                <label for="password">Nova senha</label>
                <input id="password" type="password" name="password" required>
            </div>
            <div>
                <label for="password_confirmation">Confirmar senha</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required>
            </div>
            <button class="btn btn-primary" type="submit">Salvar senha</button>
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

    <form method="POST" action="{{ route('updater.profile.2fa.enable') }}" class="form-grid" style="margin-top:12px;">
        @csrf
        <label for="code">Código para ativar 2FA</label>
        <input id="code" type="text" name="code" required>
        <button class="btn btn-primary" type="submit">Ativar 2FA</button>
    </form>

    @if(!empty($user['totp_enabled']))
        <form method="POST" action="{{ route('updater.profile.2fa.disable') }}" style="margin-top:10px;">
            @csrf
            <button class="btn btn-danger" type="submit">Desativar 2FA</button>
        </form>
    @endif
</div>
@endsection
