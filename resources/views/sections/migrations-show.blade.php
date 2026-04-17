@extends('laravel-updater::layout')
@section('page_title', 'Detalhes da migration')

@section('content')
<div class="card">
    <h3>{{ $item['migration'] }}</h3>
    <div class="update-status-grid">
        <p><strong>Arquivo:</strong> {{ $item['file_path'] ?? '-' }}</p>
        <p><strong>Status consolidado:</strong> {{ $item['status'] ?? '-' }}</p>
        <p><strong>Tentativas:</strong> {{ (int) ($item['attempt_count'] ?? 0) }}</p>
        <p><strong>Última execução:</strong> {{ $item['last_execution_at'] ?? '-' }}</p>
        <p><strong>Último run:</strong> {{ !empty($item['last_run_id']) ? '#' . (int) $item['last_run_id'] : '-' }}</p>
        <p><strong>Reconciliada:</strong> {{ !empty($item['reconciled']) ? 'SIM' : 'NÃO' }}</p>
        <p><strong>Reaplicada:</strong> {{ !empty($item['reapplied']) ? 'SIM' : 'NÃO' }}</p>
        <p><strong>Ignorada por idempotência:</strong> {{ !empty($item['idempotent_skipped']) ? 'SIM' : 'NÃO' }}</p>
    </div>
    <form method="POST" action="{{ route('updater.migrations.reapply') }}" class="form-grid" style="margin-top:12px;">
        @csrf
        <input type="hidden" name="migration" value="{{ $item['migration'] }}">
        <input type="hidden" name="redirect_to" value="show">
        <input type="hidden" name="action_type" value="run_now">
        <div>
            <label for="reason">Motivo da reaplicação</label>
            <input id="reason" type="text" name="reason" maxlength="1000" placeholder="Ex.: validação de consistência operacional">
        </div>
        <div class="form-inline">
            <button class="btn btn-primary hint-action" title="Executar reaplicação idempotente apenas desta migration" type="submit">Reaplicar individualmente agora</button>
            <a class="btn" href="{{ route('updater.migrations.index') }}">Voltar</a>
        </div>
    </form>
</div>

<div class="card" id="consistencia" style="margin-top:14px;">
    <h3>Consistência</h3>
    <p class="muted">Use este painel para comparar código x histórico x banco. Se houver divergência operacional, reaplique individualmente com motivo e rastreabilidade.</p>
    <ul>
        <li><strong>Existe no código:</strong> {{ !empty($item['exists_in_code']) ? 'SIM' : 'NÃO' }}</li>
        <li><strong>Existe na tabela migrations:</strong> {{ !empty($item['exists_in_migrations_table']) ? 'SIM' : 'NÃO' }}</li>
        <li><strong>Inconsistente:</strong> {{ !empty($item['is_inconsistent']) ? 'SIM' : 'NÃO' }}</li>
    </ul>
</div>

<div class="card" id="historico" style="margin-top:14px;">
    <h3>Histórico de tentativas</h3>
    <table>
        <thead>
        <tr>
            <th>Data/Hora</th>
            <th>Status</th>
            <th>Tentativa</th>
            <th>Run</th>
            <th>Origem</th>
            <th>Usuário</th>
            <th>Motivo</th>
            <th>Erro</th>
        </tr>
        </thead>
        <tbody>
        @forelse($attempts as $attempt)
            <tr>
                <td>{{ $attempt['created_at'] ?? '-' }}</td>
                <td>{{ $attempt['status'] ?? '-' }}</td>
                <td>{{ (int) ($attempt['attempt_no'] ?? 1) }}</td>
                <td>{{ !empty($attempt['run_id']) ? '#' . (int) $attempt['run_id'] : '-' }}</td>
                <td>{{ $attempt['origin'] ?? '-' }}</td>
                <td>{{ $attempt['requested_by'] ?? '-' }}</td>
                <td>{{ $attempt['reason'] ?? '-' }}</td>
                <td class="muted">{{ $attempt['error_message'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Sem histórico registrado.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:14px;">
    <h3>Eventos de reconciliação</h3>
    <table>
        <thead><tr><th>Data/Hora</th><th>Reconciliado</th><th>Estratégia</th><th>Run</th><th>Motivo</th></tr></thead>
        <tbody>
        @forelse($reconciliations as $event)
            <tr>
                <td>{{ $event['reconciled_at'] ?? '-' }}</td>
                <td>{{ (int) ($event['reconciled'] ?? 0) === 1 ? 'SIM' : 'NÃO' }}</td>
                <td>{{ $event['strategy'] ?? '-' }}</td>
                <td>{{ !empty($event['run_id']) ? '#' . (int) $event['run_id'] : '-' }}</td>
                <td>{{ $event['reason'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Sem reconciliações registradas.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:14px;">
    <h3>Fila e histórico de reaplicações</h3>
    <table>
        <thead><tr><th>Solicitado em</th><th>Status</th><th>Run</th><th>Solicitante</th><th>Motivo</th></tr></thead>
        <tbody>
        @forelse($reapplyQueue as $itemQueue)
            <tr>
                <td>{{ $itemQueue['requested_at'] ?? '-' }}</td>
                <td>{{ $itemQueue['status'] ?? '-' }}</td>
                <td>{{ !empty($itemQueue['run_id']) ? '#' . (int) $itemQueue['run_id'] : '-' }}</td>
                <td>{{ $itemQueue['requested_by'] ?? '-' }}</td>
                <td>{{ $itemQueue['reason'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Nenhuma reaplicação registrada.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:14px;">
    <h3>Logs relacionados</h3>
    <table>
        <thead><tr><th>Data/Hora</th><th>Nível</th><th>Mensagem</th><th>Run</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td>{{ $log['created_at'] ?? '-' }}</td>
                <td>{{ $log['level'] ?? '-' }}</td>
                <td>{{ $log['message'] ?? '-' }}</td>
                <td>{{ !empty($log['run_id']) ? '#' . (int) $log['run_id'] : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Nenhum log encontrado para esta migration.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
