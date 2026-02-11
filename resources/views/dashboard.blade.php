@extends('laravel-updater::layout')

@section('title', 'Dashboard Updater')

@section('content')
<div class="card">
    <h2>Status atual</h2>
    <pre class="muted" style="white-space: pre-wrap;">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</div>

<div class="grid">
    <div class="card">
        <h3>Ações rápidas</h3>
        <form method="POST" action="{{ route('updater.check') }}">@csrf <button type="submit">Checar atualizações</button></form>
        <div style="height:8px"></div>
        <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf <button type="submit">Disparar atualização</button></form>
        <div style="height:8px"></div>
        <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf <button class="danger" type="submit">Disparar rollback</button></form>
    </div>

    <div class="card">
        <h3>Último run</h3>
        @if($lastRun)
            <p><strong>ID:</strong> {{ $lastRun['id'] }}</p>
            <p><strong>Status:</strong> {{ $lastRun['status'] }}</p>
            <p><strong>Início:</strong> {{ $lastRun['started_at'] }}</p>
            <p><strong>Fim:</strong> {{ $lastRun['finished_at'] ?? '-' }}</p>
        @else
            <p class="muted">Sem runs registrados.</p>
        @endif
    </div>
</div>

<div class="card">
    <h3>Histórico</h3>
    <table>
        <thead>
        <tr>
            <th>ID</th><th>Status</th><th>Início</th><th>Fim</th>
        </tr>
        </thead>
        <tbody>
        @forelse($runs as $run)
            <tr>
                <td>{{ $run['id'] }}</td>
                <td>{{ $run['status'] }}</td>
                <td>{{ $run['started_at'] }}</td>
                <td>{{ $run['finished_at'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Nenhum run encontrado.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
