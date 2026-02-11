@extends('laravel-updater::layout')

@section('title', '2FA Updater')

@section('content')
<div class="card" style="max-width:460px; margin: 0 auto;">
    <h2>Confirmação 2FA</h2>
    <p class="muted">Digite o código de 6 dígitos do seu app autenticador.</p>
    <form method="POST" action="{{ route('updater.2fa.verify') }}">
        @csrf
        <label for="code">Código</label>
        <input id="code" type="text" name="code" maxlength="6" required>
        <div style="margin-top:14px;">
            <button type="submit">Validar</button>
        </div>
    </form>
</div>
@endsection
