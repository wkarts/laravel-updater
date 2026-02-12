@extends('laravel-updater::layout-auth')

@section('title', 'Código 2FA')

@section('content')
<h2 class="up-auth-title">Entrar</h2>
<p class="up-auth-subtitle">Informe o código 2FA do autenticador ou um recovery code.</p>
<form method="POST" action="{{ route('updater.2fa.verify') }}" class="form-grid up-auth-form">
    @csrf
    <div>
        <label for="code">Código 2FA ou Recovery</label>
        <input id="code" type="text" name="code" placeholder="000000 ou ABCDEF1234" required>
    </div>
    <button class="btn btn-primary up-auth-submit" type="submit">Entrar</button>
</form>
@endsection
