@extends('laravel-updater::layout-auth')

@section('title', 'Código 2FA')

@section('content')
<h2 class="up-auth-title">Entrar</h2>
<p class="up-auth-subtitle">Acesse sua área administrativa do Updater.</p>
<form method="POST" action="{{ route('updater.2fa.verify') }}" class="form-grid up-auth-form">
    @csrf
    <div>
        <label for="code">Código 2FA</label>
        <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" placeholder="000000" required>
    </div>
    <button class="btn btn-primary up-auth-submit" type="submit">Entrar</button>
</form>
@endsection
