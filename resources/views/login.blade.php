@extends('laravel-updater::layout')

@section('title', 'Login Updater')

@section('content')
<div class="card" style="max-width:460px; margin: 0 auto;">
    <h2>Entrar no Updater</h2>
    <p class="muted">Autenticação independente do app hospedeiro.</p>
    <form method="POST" action="{{ route('updater.login.submit') }}">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required>
        </div>
        <div style="margin-top:10px;">
            <label for="password">Senha</label>
            <input id="password" type="password" name="password" required>
        </div>
        <div style="margin-top:14px;">
            <button type="submit">Entrar</button>
        </div>
    </form>
</div>
@endsection
