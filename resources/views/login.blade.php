@extends('laravel-updater::layout-auth')

@section('title', 'Entrar')

@section('content')
<h2>Entrar</h2>
<p class="muted">Use suas credenciais administrativas para acessar o Updater Manager.</p>
<form method="POST" action="{{ route('updater.login.submit') }}" class="form-grid" style="margin-top: 12px;">
    @csrf
    <div>
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required>
    </div>
    <div>
        <label for="password">Senha</label>
        <input id="password" type="password" name="password" required>
    </div>
    <button class="btn btn-primary" type="submit">Entrar</button>
</form>
@endsection
