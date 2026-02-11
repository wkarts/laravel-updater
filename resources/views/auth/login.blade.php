@extends('laravel-updater::auth.layout')
@section('title', 'Login Updater')
@section('content')
<div class="card" style="max-width:420px;margin:60px auto;">
    <h2>Entrar no Updater</h2>
    <p class="muted">Acesso administrativo independente do auth da aplicação.</p>
    @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('updater.login.submit') }}">
        @csrf
        <div class="row" style="flex-direction:column;">
            <input type="email" name="email" placeholder="E-mail" required>
            <input type="password" name="password" placeholder="Senha" required>
            <button type="submit">Entrar</button>
        </div>
    </form>
</div>
@endsection
