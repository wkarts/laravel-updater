@extends('laravel-updater::layout')

@section('title', 'Dashboard Updater')
@section('page_title', 'Dashboard')
@section('breadcrumbs', 'Dashboard')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Resumo rápido</h3>
        <p><span class="badge">Perfil ativo: {{ $activeProfile['name'] ?? 'padrão' }}</span></p>
        <p><span class="badge">Fonte ativa: {{ $activeSource['name'] ?? 'repositório local' }}</span></p>
        <div class="form-inline" style="margin-top:10px;">
            <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf <button class="btn btn-primary" type="submit">Executar atualização</button></form>
            <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf <button class="btn btn-danger" type="submit">Executar rollback</button></form>
        </div>
    </div>
    <div class="card">
        <h3>Status do sistema</h3>
        <pre class="muted" style="white-space: pre-wrap;">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</div>

<div class="card">
    <h3>Histórico de execuções</h3>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Status</th>
                <th>Início</th>
                <th>Fim</th>
            </tr>
            </thead>
            <tbody>
            @forelse($runs as $run)
                <tr>
                    <td>#{{ $run['id'] }}</td>
                    <td>
                        @if(($run['status'] ?? '') === 'success')
                            <span class="badge success">Sucesso</span>
                        @elseif(($run['status'] ?? '') === 'failed')
                            <span class="badge warn">Falha</span>
                        @else
                            <span class="badge">{{ $run['status'] }}</span>
                        @endif
                    </td>
                    <td>{{ $run['started_at'] }}</td>
                    <td>{{ $run['finished_at'] ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">Nenhuma execução encontrada.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
