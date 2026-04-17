@extends('laravel-updater::layout')
@section('page_title', 'Auditoria de Migrations')

@section('content')
<div class="card">
    <h3>Dashboard de auditoria</h3>
    <div class="update-status-grid">
        <p><strong>Total no projeto:</strong> {{ (int) ($metrics['total'] ?? 0) }}</p>
        <p><strong>Aplicadas com sucesso:</strong> {{ (int) ($metrics['success'] ?? 0) }}</p>
        <p><strong>Pendentes:</strong> {{ (int) ($metrics['pending'] ?? 0) }}</p>
        <p><strong>Com erro:</strong> {{ (int) ($metrics['error'] ?? 0) }}</p>
        <p><strong>Reaplicadas:</strong> {{ (int) ($metrics['reapplied'] ?? 0) }}</p>
        <p><strong>Reconciliadas:</strong> {{ (int) ($metrics['reconciled'] ?? 0) }}</p>
        <p><strong>Ignoradas por idempotência:</strong> {{ (int) ($metrics['idempotent_skipped'] ?? 0) }}</p>
        <p><strong>Inconsistentes:</strong> {{ (int) ($metrics['inconsistent'] ?? 0) }}</p>
        <p><strong>Último run com migration:</strong> {{ $metrics['last_run_id'] ? '#' . $metrics['last_run_id'] : '-' }}</p>
        <p><strong>Último erro:</strong> {{ $metrics['last_error_migration'] ?? '-' }}</p>
        <p><strong>Integridade geral:</strong> {{ (int) ($metrics['integrity_percent'] ?? 0) }}%</p>
    </div>
</div>

<div class="card" style="margin-top:14px;">
    <h3>Filtros</h3>
    <form method="GET" action="{{ route('updater.migrations.index') }}" class="form-grid" style="margin-top:10px;">
        <div>
            <label for="q">Nome da migration</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ex: create_users_table">
        </div>
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                @foreach(['pendente', 'aplicada com sucesso', 'aplicada com ressalvas', 'erro', 'ignorada por idempotência', 'reconciliada', 'reaplicada', 'inconsistente', 'órfã', 'ausente no banco', 'ausente no código'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="run_id">Run</label>
            <select id="run_id" name="run_id">
                <option value="">Todos</option>
                @foreach($runs as $run)
                    <option value="{{ (int) $run['id'] }}" @selected((int) ($filters['run_id'] ?? 0) === (int) $run['id'])>#{{ (int) $run['id'] }} ({{ $run['status'] ?? '-' }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>
                <input type="checkbox" name="inconsistent" value="1" {{ !empty($filters['inconsistent']) ? 'checked' : '' }}>
                Somente inconsistentes
            </label>
        </div>
        <div class="form-inline">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn" href="{{ route('updater.migrations.index') }}">Limpar</a>
        </div>
    </form>
</div>

<div class="card" style="margin-top:14px;">
    <h3>Grid de auditoria</h3>
    <div style="overflow-x:auto;">
        <table>
            <thead>
            <tr>
                <th>Migration</th>
                <th>Arquivo</th>
                <th>Status atual</th>
                <th>Aplicada</th>
                <th>Erro</th>
                <th>Reconciliada</th>
                <th>Reaplicada</th>
                <th>Tentativas</th>
                <th>Última execução</th>
                <th>Run</th>
                <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td><code>{{ $row['migration'] }}</code></td>
                    <td class="muted">{{ $row['file_path'] ?? '-' }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ !empty($row['executed']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ !empty($row['has_error']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ !empty($row['reconciled']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ !empty($row['reapplied']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ (int) ($row['attempt_count'] ?? 0) }}</td>
                    <td>{{ $row['last_execution_at'] ?? '-' }}</td>
                    <td>{{ !empty($row['last_run_id']) ? '#' . (int) $row['last_run_id'] : '-' }}</td>
                    <td>
                        <div class="form-inline">
                            <a class="btn btn-secondary" href="{{ route('updater.migrations.show', ['migration' => $row['migration']]) }}">Visualizar detalhes</a>
                            <a class="btn btn-secondary" href="{{ route('updater.migrations.show', ['migration' => $row['migration']]) }}#historico">Ver histórico</a>
                            <a class="btn btn-secondary" href="{{ route('updater.section', ['section' => 'logs']) }}?q={{ urlencode($row['migration']) }}">Ver logs</a>
                            <a class="btn btn-secondary" href="{{ route('updater.migrations.show', ['migration' => $row['migration']]) }}#consistencia">Analisar consistência</a>
                        </div>
                        <form method="POST" action="{{ route('updater.migrations.reapply') }}" class="form-grid" style="margin-top:8px;">
                            @csrf
                            <input type="hidden" name="migration" value="{{ $row['migration'] }}">
                            <input type="hidden" name="redirect_to" value="index">
                            <label>
                                Motivo (opcional)
                                <input type="text" name="reason" placeholder="Ex.: corrigir inconsistência detectada">
                            </label>
                            <div class="form-inline">
                                <button class="btn" type="submit" name="action_type" value="queue">Marcar para reaplicação</button>
                                <button class="btn btn-primary" type="submit" name="action_type" value="run_now">Reaplicar individualmente agora</button>
                            </div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="muted">Nenhuma migration encontrada para os filtros aplicados.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
