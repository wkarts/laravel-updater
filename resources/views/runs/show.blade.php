@extends('laravel-updater::layout')
@section('page_title', 'Detalhes da execução')

@section('content')
@php
    $report = $diagnostics ?? [];
    $summary = $report['summary'] ?? [];
    $error = $report['error']['message'] ?? null;
    $stages = $report['stages'] ?? [];
    $events = array_slice($report['events'] ?? [], -300);
    $options = $report['options'] ?? [];
    $status = (string) ($run['status'] ?? 'unknown');
    $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
@endphp

<div class="card run-report-header">
    <div class="run-report-top">
        <div>
            <h3>Diagnóstico da execução #{{ (int) $run['id'] }}</h3>
            <p class="muted">Registros próprios do Laravel Updater, sem logs da aplicação hospedeira.</p>
        </div>
        <div class="form-inline">
            <span class="run-pill run-pill-{{ $status === 'success' ? 'ok' : ($status === 'failed' ? 'failed' : 'neutral') }}">{{ strtoupper($status) }}</span>
            <a class="btn btn-action-sm" href="{{ route('updater.runs.diagnostics.download', ['id' => $run['id'], 'format' => 'json']) }}">Baixar JSON</a>
            <a class="btn btn-action-sm" href="{{ route('updater.runs.diagnostics.download', ['id' => $run['id'], 'format' => 'zip']) }}">Baixar ZIP</a>
        </div>
    </div>
    @if($status === 'failed')
        <div class="run-error-panel" role="alert">
            <strong>Motivo da falha{{ !empty($summary['first_failed_step']) ? ' — ' . $summary['first_failed_step'] : '' }}</strong>
            <p>{{ $error ?: 'A execução foi marcada como falha. Confira a linha do tempo e os eventos registrados.' }}</p>
        </div>
    @endif
    <div class="run-metrics">
        <div><span>Status</span><strong>{{ strtoupper($status) }}</strong></div>
        <div><span>Início</span><strong>{{ $run['started_at'] ?? '-' }}</strong></div>
        <div><span>Fim</span><strong>{{ $run['finished_at'] ?? '-' }}</strong></div>
        <div><span>Duração</span><strong>{{ isset($summary['duration_ms']) ? number_format($summary['duration_ms']/1000, 2, ',', '.') . ' s' : '-' }}</strong></div>
        <div><span>Eventos</span><strong>{{ $summary['event_count'] ?? 0 }}</strong></div>
        <div><span>Erros / avisos</span><strong>{{ $summary['error_count'] ?? 0 }} / {{ $summary['warning_count'] ?? 0 }}</strong></div>
    </div>
    <p class="muted run-revisions">
        Revisão anterior: <code>{{ $run['revision_before'] ?? '-' }}</code>
        <span aria-hidden="true">→</span>
        Revisão alvo: <code>{{ $run['revision_after'] ?? '-' }}</code>
    </p>
    @if(!empty($summary['truncated_events']))
        <p class="muted">Esta execução possui mais registros do que o limite seguro por relatório. Foram incluídos os últimos {{ $summary['included_events'] }} eventos.</p>
    @endif
</div>

<div class="card">
    <h3>Linha do tempo das etapas</h3>
    <div class="table-wrap">
        <table class="audit-grid-table run-stage-table">
            <thead><tr><th>Etapa</th><th>Resultado</th><th>Duração</th><th>Rollback</th><th>Diagnóstico</th></tr></thead>
            <tbody>
            @forelse($stages as $stage)
                <tr>
                    <td><strong>{{ $stage['step'] }}</strong></td>
                    <td><span class="run-pill run-pill-{{ $stage['status'] === 'success' ? 'ok' : ($stage['status'] === 'failed' ? 'failed' : 'neutral') }}">{{ $stage['status'] }}</span></td>
                    <td>{{ isset($stage['duration_ms']) ? number_format($stage['duration_ms']/1000, 2, ',', '.') . ' s' : '-' }}</td>
                    <td>{{ $stage['rollback'] === 'applied' ? 'Rotina executada' : ($stage['rollback'] === 'failed' ? 'Falhou' : '-') }}</td>
                    <td>
                        @if(!empty($stage['details']))
                            <details class="run-event-details">
                                <summary>Ver dados</summary>
                                <pre>{{ json_encode($stage['details'], $jsonFlags) }}</pre>
                            </details>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Sem etapas detalhadas nesta execução antiga.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="muted">“Rotina executada” indica que o callback de rollback foi chamado; não comprova restauração integral de dados ou DDL.</p>
</div>

@if(!empty($report['migration_attempts']))
<div class="card">
    <h3>Migrations auditadas ({{ count($report['migration_attempts']) }})</h3>
    <div class="table-wrap">
        <table class="audit-grid-table">
            <thead><tr><th>Migration</th><th>Status</th><th>Tentativa</th><th>Erro</th></tr></thead>
            <tbody>
            @foreach($report['migration_attempts'] as $migration)
                <tr>
                    <td>{{ $migration['migration'] ?? '-' }}</td>
                    <td>{{ $migration['status'] ?? '-' }}</td>
                    <td>{{ $migration['attempt_no'] ?? '-' }}</td>
                    <td>{{ $migration['error_message'] ?? '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <h3>Eventos técnicos da execução ({{ $summary['event_count'] ?? 0 }})</h3>
    <p class="muted">Exibidos até 300 eventos recentes, em ordem cronológica. O relatório para download contém até {{ \Argws\LaravelUpdater\Support\RunDiagnosticsService::MAX_EVENTS }} eventos e os contextos sanitizados.</p>
    <div class="table-wrap run-events-wrap">
        <table class="audit-grid-table run-event-table">
            <thead><tr><th>Horário</th><th>Nível</th><th>Etapa</th><th>Evento</th><th>Contexto</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                <tr class="{{ $event['level'] === 'error' ? 'run-event-error' : '' }}">
                    <td><time>{{ $event['timestamp'] }}</time></td>
                    <td>{{ strtoupper($event['level']) }}</td>
                    <td><code>{{ $event['step'] ?: '-' }}</code></td>
                    <td>{{ $event['message'] }}@if(!empty($event['context']['erro'])) <strong>{{ $event['context']['erro'] }}</strong>@endif</td>
                    <td>
                        @if(!empty($event['context']))
                            <details class="run-event-details">
                                <summary>Detalhes</summary>
                                <pre>{{ json_encode($event['context'], $jsonFlags) }}</pre>
                            </details>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Sem eventos SQLite associados a esta execução.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <details class="run-event-details">
        <summary>Opções da execução (dados sensíveis ocultados)</summary>
        <pre>{{ json_encode($options, $jsonFlags) }}</pre>
    </details>

    @if(($run['status'] ?? '') === 'DRY_RUN' && ($pendingApproval ?? false))
        <hr style="margin:12px 0;">
        <h4>Aprovar e executar atualização real</h4>
        <form method="POST" action="{{ route('updater.runs.approve', ['id' => $run['id']]) }}" class="form-grid">
            @csrf
            <div>
                <label for="password">Senha do administrador</label>
                <input id="password" name="password" type="password" required>
            </div>
            <div class="form-inline">
                <button class="btn btn-primary" type="submit">Aprovar e executar</button>
            </div>
        </form>
    @endif
</div>
@endsection
