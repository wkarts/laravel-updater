@extends('laravel-updater::layout')
@section('page_title', 'Atualizações')
@section('breadcrumbs', 'Atualizações')

@section('content')
@php
    $lastRun = $lastRun ?? null;
    $lastError = null;
    if (is_array($lastRun) && !empty($lastRun['error_json'])) {
        $decoded = json_decode((string) $lastRun['error_json'], true);
        $lastError = is_array($decoded) ? ($decoded['message'] ?? null) : null;
    }
    $lastRunLogs = $lastRunLogs ?? [];
@endphp

<div class="grid">
    <div class="card">
        <h3>Status de atualização</h3>
        <p>Perfil ativo: <strong>{{ $activeProfile['name'] ?? '-' }}</strong></p>
        <p>Fonte ativa: <strong>{{ $activeSource['name'] ?? '-' }}</strong></p>
        <p>Tipo de fonte: <strong>{{ strtoupper((string) ($activeSource['type'] ?? '-')) }}</strong></p>
        <p class="muted">Run mais recente: <strong>#{{ $lastRun['id'] ?? '-' }}</strong> · Status: <strong>{{ strtoupper((string) ($lastRun['status'] ?? 'N/A')) }}</strong></p>

        <div class="form-inline" style="margin-top:10px;">
            <a class="btn" href="{{ route('updater.logs.report.download', ['run_id' => $lastRun['id'] ?? null]) }}">Baixar relatório JSON da última execução</a>
            <a class="btn" href="{{ route('updater.backups.log.download') }}">Baixar updater.log</a>
        </div>
    </div>

    <div class="card">
        <h3>Última falha detalhada</h3>
        @if(($lastRun['status'] ?? '') === 'failed')
            <p><strong>Motivo:</strong> {{ $lastError ?: 'Falha sem mensagem estruturada.' }}</p>
            <p class="muted">Sugestão: valide o repositório Git, fila assíncrona e permissões de execução CLI.</p>
        @else
            <p class="muted">Nenhuma falha registrada na última execução.</p>
        @endif
    </div>
</div>

<div class="card">
    <h3>Executar atualização</h3>
    <form method="POST" action="{{ route('updater.trigger.update') }}" class="form-grid" style="margin-top:10px;">
        @csrf
        <div>
            <label for="seed">Seeder opcional</label>
            <input id="seed" name="seed" placeholder="Database\\Seeders\\ExampleSeeder">
        </div>
        <div class="form-inline">
            <button class="btn btn-primary" type="submit">Executar update agora</button>
            <a class="btn btn-ghost" href="{{ route('updater.section', ['section' => 'runs']) }}">Acompanhar execuções</a>
        </div>
    </form>

    <form method="POST" action="{{ route('updater.trigger.dryrun') }}" style="margin-top:12px;">
        @csrf
        <button class="btn" type="submit">Simular (Dry-run)</button>
    </form>

    <p class="muted" style="margin-top:10px;">A execução web será bloqueada automaticamente quando o ambiente não suportar disparo assíncrono em CLI.</p>
</div>

<div class="card">
    <h3>Últimos eventos da execução mais recente</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Data</th><th>Nível</th><th>Mensagem</th></tr></thead>
            <tbody>
            @forelse(array_slice($lastRunLogs, 0, 10) as $log)
                <tr>
                    <td>{{ $log['created_at'] ?? '-' }}</td>
                    <td>{{ strtoupper((string) ($log['level'] ?? 'info')) }}</td>
                    <td>{{ $log['message'] ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Sem eventos registrados para a última execução.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
