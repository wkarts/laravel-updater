@extends('laravel-updater::layout')
@section('title', 'Editar usuário')
@section('page_title', 'Editar usuário')
@section('breadcrumbs', 'Usuários / Editar')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Editar usuário</h3>
        @if(!empty($masterEmail))<p class="muted">Usuário master definido em <code>UPDATER_UI_MASTER_EMAIL</code>: <strong>{{ $masterEmail }}</strong>.</p>@endif
        <form method="POST" action="{{ route('updater.users.update', $user['id']) }}" class="form-grid" style="margin-top: 10px;">
            @csrf @method('PUT')
            @include('laravel-updater::users.form', ['user' => $user])
            <button class="btn btn-primary" type="submit">Salvar alterações</button>
        </form>
    </div>
    <div class="card">
        <h3>Segurança</h3>
        <p>2FA atual: <strong>{{ (int) $user['totp_enabled'] === 1 ? 'Ativo' : 'Inativo' }}</strong></p>
        <form method="POST" action="{{ route('updater.users.2fa.reset', $user['id']) }}" class="form-grid" onsubmit="return confirm('Deseja resetar o 2FA deste usuário?')">
            @csrf
            <label for="admin_password">Confirme sua senha de admin</label>
            <input id="admin_password" type="password" name="admin_password" required>
            <button class="btn btn-secondary" type="submit">Resetar segredo 2FA</button>
        </form>
    </div>
</div>
@endsection
