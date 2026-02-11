@extends('laravel-updater::layout-auth')

@section('title', 'Entrar')

@section('content')
<h2 class="auth-title">Entrar</h2>
<p class="auth-subtitle">Acesse com suas credenciais administrativas para continuar.</p>
<form method="POST" action="{{ route('updater.login.submit') }}" class="form-grid auth-form" style="margin-top: 14px;">
    @csrf
    <div>
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
    </div>
    <div>
        <label for="password">Senha</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primary auth-submit" type="submit">Entrar</button>
</form>
@endsection
