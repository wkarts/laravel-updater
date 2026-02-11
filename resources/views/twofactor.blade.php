@extends('laravel-updater::layout-auth')

@section('title', 'Código 2FA')

@section('content')
<h2 class="auth-title">Verificação em duas etapas</h2>
<p class="auth-subtitle">Informe o código de 6 dígitos do seu aplicativo autenticador.</p>
<form method="POST" action="{{ route('updater.2fa.verify') }}" class="form-grid auth-form" style="margin-top: 14px;">
    @csrf
    <div>
        <label for="code">Código 2FA</label>
        <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" placeholder="000000" required>
    </div>
    <button class="btn btn-primary auth-submit" type="submit">Validar código</button>
</form>
@endsection
