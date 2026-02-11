@extends('laravel-updater::layout')

@section('title', 'Dashboard Updater')
@section('page_title', 'Dashboard')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Status atual</h3>
        <pre class="muted" style="white-space: pre-wrap;">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    <div class="card">
        <h3>Ações rápidas</h3>
        <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf <button type="submit">Executar update agora</button></form>
        <div style="height:8px"></div>
        <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf <button class="danger" type="submit">Rollback</button></form>
        <p class="muted">Profile ativo: {{ $activeProfile['name'] ?? 'padrão' }}</p>
        <p class="muted">Source ativa: {{ $activeSource['name'] ?? 'repo local' }}</p>
    </div>
</div>

<div class="card">
    <h3>Histórico</h3>
    <table>
        <thead><tr><th>ID</th><th>Status</th><th>Início</th><th>Fim</th></tr></thead>
        <tbody>
        @forelse($runs as $run)
            <tr><td>{{ $run['id'] }}</td><td>{{ $run['status'] }}</td><td>{{ $run['started_at'] }}</td><td>{{ $run['finished_at'] ?? '-' }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">Nenhum run encontrado.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
