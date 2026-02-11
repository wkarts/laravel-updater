@extends('laravel-updater::auth.layout')
@section('title', '2FA Updater')
@section('content')
<div class="card" style="max-width:420px;margin:60px auto;">
    <h2>Validação 2FA</h2>
    <p class="muted">Informe o código de 6 dígitos do autenticador.</p>
    @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('updater.2fa.submit') }}">
        @csrf
        <div class="row" style="flex-direction:column;">
            <input type="text" name="code" maxlength="6" placeholder="000000" required>
            <button type="submit">Validar</button>
        </div>
    </form>
</div>
@endsection
