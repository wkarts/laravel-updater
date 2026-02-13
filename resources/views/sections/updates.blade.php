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
    <h3>Status de atualização</h3>
    <p><strong>Revisão atual:</strong> {{ $statusCheck['current_revision'] ?? '-' }}</p>
    <p><strong>Revisão remota:</strong> {{ $statusCheck['remote'] ?? '-' }}</p>
    <p><strong>Commits pendentes:</strong> {{ (int) ($statusCheck['behind_by_commits'] ?? 0) }}</p>
    <p><strong>Última tag remota:</strong> {{ $statusCheck['latest_tag'] ?? '-' }}</p>
    <p><strong>Update disponível:</strong>
        @if((bool) ($statusCheck['has_updates'] ?? false) || (bool) ($statusCheck['has_update_by_tag'] ?? false))
            <span style="color:#16a34a;font-weight:700;">SIM</span>
        @else
            <span style="color:#64748b;font-weight:700;">NÃO</span>
        @endif
    </p>
    @if(!empty($statusCheck['warning']))
        <p class="muted">{{ $statusCheck['warning'] }}</p>
    @endif
</div>

<div class="card" style="margin-top:14px;">
    <h3>Executar atualização</h3>
    <form method="POST" action="{{ route('updater.trigger.update') }}" class="form-grid" style="margin-top:10px;">
        @csrf

        <div>
            <label for="profile_id">Perfil ativo</label>
            <select id="profile_id" name="profile_id" required>
                @foreach($profiles as $profile)
                    <option value="{{ $profile['id'] }}" @selected((int) old('profile_id', $activeProfile['id'] ?? 0) === (int) $profile['id'])>
                        {{ $profile['name'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="source_id">Fonte ativa</label>
            <select id="source_id" name="source_id" required>
                @foreach($sources as $source)
                    <option value="{{ $source['id'] }}" @selected((int) old('source_id', $activeSource['id'] ?? 0) === (int) $source['id'])>
                        {{ $source['name'] }} ({{ $source['branch'] ?? 'main' }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="update_mode">Modo de atualização</label>
            <select id="update_mode" name="update_mode" required onchange="document.getElementById('tag-wrapper').style.display = this.value === 'tag' ? 'block' : 'none';">
                <option value="merge" @selected(old('update_mode', 'merge') === 'merge')>merge (prioridade)</option>
                <option value="ff-only" @selected(old('update_mode') === 'ff-only')>ff-only</option>
                <option value="tag" @selected(old('update_mode') === 'tag')>tag</option>
                @if($fullUpdateEnabled)
                    <option value="full-update" @selected(old('update_mode') === 'full-update')>full update</option>
                @endif
            </select>
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

<div class="card" id="update-progress-card" style="margin-top:14px;">
    <h3>Progresso da atualização/rollback</h3>
    <div class="progress-track"><div class="progress-fill" id="update-progress-fill" style="width:0%"></div></div>
    <p id="update-progress-message" class="muted">Aguardando execução.</p>
    <ul id="update-progress-logs" class="muted" style="margin:0; padding-left:18px;"></ul>
</div>
