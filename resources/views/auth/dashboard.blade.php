@extends('laravel-updater::auth.layout')
@section('title', 'Dashboard Updater')
@section('content')
<div class="card">
    <div class="row" style="justify-content:space-between;align-items:center;">
        <div>
            <h2>Laravel Updater</h2>
            <p class="muted">Usuário: {{ $user['email'] ?? 'n/a' }}</p>
        </div>
        <div class="row">
            <a href="{{ route('updater.profile') }}">Perfil</a>
            <form method="POST" action="{{ route('updater.logout') }}">@csrf<button class="danger" type="submit">Sair</button></form>
        </div>
    </div>
    @if (session('status')) <p>{{ session('status') }}</p> @endif
    <p><strong>Status:</strong> {{ $status['last_run']['status'] ?? 'idle' }}</p>
    <div class="row">
        <form method="POST" action="{{ route('updater.check') }}">@csrf<button type="submit">Checar atualizações</button></form>
        <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf<button type="submit">Disparar atualização</button></form>
        <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf<button class="danger" type="submit">Rollback último</button></form>
    </div>
</div>

<div class="card">
    <h3>Último run</h3>
    <pre>{{ json_encode($lastRun, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
</div>

<div class="card">
    <h3>Histórico</h3>
    <table>
        <thead><tr><th>ID</th><th>Início</th><th>Fim</th><th>Status</th><th>Before</th><th>After</th></tr></thead>
        <tbody>
        @foreach($runs as $run)
            <tr>
                <td>{{ $run['id'] }}</td><td>{{ $run['started_at'] }}</td><td>{{ $run['finished_at'] }}</td><td>{{ $run['status'] }}</td><td>{{ $run['revision_before'] }}</td><td>{{ $run['revision_after'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
