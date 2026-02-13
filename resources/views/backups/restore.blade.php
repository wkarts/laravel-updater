@extends('laravel-updater::layout')
@section('page_title', 'Confirmar restore')

@section('content')
<div class="card">
    <h3>Restaurar backup #{{ $backup['id'] }}</h3>
    <p><strong>Tipo:</strong> {{ strtoupper($backup['type']) }}</p>
    <p><strong>Arquivo:</strong> {{ $backup['path'] }}</p>
    <p class="muted">A ação é restrita para admin e será auditada.</p>

    <form method="POST" action="{{ route('updater.backups.restore', ['id' => $backup['id']]) }}" class="form-grid" style="margin-top: 12px; max-width: 560px;">
        @csrf
        <div>
            <label for="confirmacao">Digite RESTAURAR para confirmar</label>
            <input id="confirmacao" name="confirmacao" required placeholder="RESTAURAR">
        </div>
        <div>
            <label for="password">Senha de administrador</label>
            <input id="password" type="password" name="password" required>
        </div>

        <div class="form-inline" style="gap:8px;">
            <button class="btn btn-danger" type="submit">Confirmar restore</button>
            <a class="btn" href="{{ route('updater.section', ['section' => 'backups']) }}">Cancelar</a>
        </div>
    </form>
</div>
@endsection
