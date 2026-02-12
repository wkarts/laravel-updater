@extends('laravel-updater::layout')

@section('title', 'Meu perfil')
@section('page_title', 'Meu perfil')
@section('breadcrumbs', 'Perfil')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Segurança</h3>
        <p><strong>E-mail:</strong> {{ $user['email'] }}</p>
        <p><strong>2FA:</strong> {{ !empty($user['totp_enabled']) ? 'Ativado' : 'Desativado' }}</p>
        <p><strong>Recovery codes disponíveis:</strong> {{ $recoverySummary['disponiveis'] ?? 0 }} de {{ $recoverySummary['total'] ?? 0 }}</p>
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
    <h3>2FA (TOTP + QRCode)</h3>
    <p class="muted">Escaneie o QRCode no app autenticador, confirme um código e guarde os recovery codes.</p>

    @if(empty($user['totp_enabled']))
        <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-bottom:12px;">
            <div>
                <img src="{{ $qrcodeDataUri }}" alt="QRCode 2FA" style="width:220px; height:220px; border:1px solid #ddd; border-radius:8px;">
            </div>
            <div>
                <label>Secret</label>
                <code>{{ $pendingTotpSecret }}</code>
                <p class="muted" style="margin-top:8px;">URI OTPAuth:</p>
                <code>{{ $otpauthUri }}</code>
            </div>
        </div>

        <form method="POST" action="{{ route('updater.profile.2fa.enable') }}" class="form-grid" style="margin-top:12px;">
            @csrf
            <label for="code">Código para ativar 2FA</label>
            <input id="code" type="text" name="code" required>
            <button class="btn btn-primary" type="submit">Ativar 2FA</button>
        </form>
    @else
        <p class="muted">2FA já ativado para sua conta.</p>
        <form method="POST" action="{{ route('updater.profile.2fa.disable') }}" style="margin-top:10px;">
            @csrf
            <button class="btn btn-danger" type="submit">Desativar 2FA</button>
        </form>
    @endif
</div>

<div class="card">
    <h3>Recovery codes</h3>
    <p class="muted">Os códigos são mostrados apenas após ativação/regeneração. Códigos antigos não são exibidos novamente.</p>

    @if(!empty($newRecoveryCodes))
        <div class="code-block" style="padding:12px; background:#111827; color:#fff; border-radius:8px; margin-bottom:12px;">
            @foreach($newRecoveryCodes as $rc)
                <div>{{ $rc }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('updater.profile.2fa.recovery.regenerate') }}" class="form-grid">
        @csrf
        <label for="regen_password">Senha atual para gerar novos códigos</label>
        <input id="regen_password" type="password" name="password" required>
        <button class="btn btn-primary" type="submit">Gerar novos recovery codes</button>
    </form>
</div>
@endsection
