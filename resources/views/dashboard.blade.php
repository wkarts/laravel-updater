@extends('laravel-updater::layout')

@section('title', 'Dashboard Updater')
@section('page_title', 'Dashboard')
@section('breadcrumbs', 'Dashboard')

@section('content')
<div class="grid">
    <div class="card dashboard-summary-card">
        <h3>Resumo rápido</h3>
        <p><span class="badge">Perfil ativo: {{ $activeProfile['name'] ?? 'padrão' }}</span></p>
        <p><span class="badge">Fonte ativa: {{ $activeSource['name'] ?? 'repositório local' }}</span></p>
        <div class="form-inline dashboard-actions" style="margin-top:10px;">
            <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf <button class="btn btn-primary" data-update-action="1" type="submit">Executar atualização</button></form>
            <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf <button class="btn btn-danger" type="submit">Executar rollback</button></form>
            <form method="POST" action="{{ route('updater.maintenance.on') }}" onsubmit="return updaterConfirmMaintenance(this, 'habilitar')">
                @csrf
                <input type="hidden" name="maintenance_confirmation" value="">
                <button class="btn btn-secondary" type="submit">Habilitar manutenção agora</button>
            </form>
            <form method="POST" action="{{ route('updater.maintenance.off') }}" onsubmit="return updaterConfirmMaintenance(this, 'desabilitar')">
                @csrf
                <input type="hidden" name="maintenance_confirmation" value="">
                <button class="btn btn-ghost" type="submit">Desabilitar manutenção</button>
            </form>
        </div>
    </div>
    <div class="card dashboard-status-card">
        <h3>Status do sistema</h3>
        <pre class="muted" style="white-space: pre-wrap;">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</div>

<div class="card" id="update-progress-card">
    <h3>Andamento da atualização</h3>
    <div class="progress-track"><div class="progress-fill" id="update-progress-fill" style="width:0%"></div></div>
    <p id="update-progress-message" class="muted">Aguardando execução.</p>
    <ul id="update-progress-logs" class="muted" style="margin:0; padding-left:18px;"></ul>
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

<script>
function updaterConfirmMaintenance(form, actionLabel) {
    const answer = window.prompt('Confirme para ' + actionLabel + ' a manutenção digitando MANUTENCAO');
    if (!answer) {
        return false;
    }
    form.querySelector('input[name="maintenance_confirmation"]').value = answer;
    return true;
}
</script>
