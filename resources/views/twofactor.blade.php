@extends('laravel-updater::layout-auth')

@section('title', 'Código 2FA')

@section('content')
<h2>Verificação em duas etapas</h2>
<p class="muted">Informe o código de 6 dígitos do aplicativo autenticador.</p>
<form method="POST" action="{{ route('updater.2fa.verify') }}" class="form-grid" style="margin-top: 12px;">
    @csrf
    <div>
        <label for="code">Código 2FA</label>
        <input id="code" type="text" name="code" maxlength="6" required>
    </div>
    <button class="btn btn-primary" type="submit">Validar código</button>
</form>
@endsection
