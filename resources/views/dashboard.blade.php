@extends('laravel-updater::layout')

@section('title', 'Dashboard Updater')
@section('page_title', 'Dashboard')
@section('breadcrumbs', 'Dashboard')

@section('content')
@php
    $user = request()->attributes->get('updater_user');
    $perm = app(\Argws\LaravelUpdater\Support\UiPermission::class);
    $canRollback = !is_array($user) || $perm->has($user, 'updates.rollback');
    $canManageMaintenance = !is_array($user) || $perm->has($user, 'maintenance.manage');
@endphp

<div class="grid">
    <div class="card dashboard-summary-card">
        <h3>Resumo rápido</h3>
        <p><span class="badge">Perfil ativo: {{ $activeProfile['name'] ?? 'padrão' }}</span></p>
        <p><span class="badge">Fonte ativa: {{ $activeSource['name'] ?? 'repositório local' }}</span></p>
        <div class="form-inline dashboard-actions" style="margin-top:10px;">
            <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf <button class="btn btn-primary" data-update-action="1" type="submit">Executar atualização</button></form>
            @if($canRollback)
                <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf <button class="btn btn-danger" type="submit">Executar rollback</button></form>
            @endif
            @if($canManageMaintenance)
                <form method="POST" action="{{ route('updater.maintenance.on') }}" data-maintenance-form="on">
                    @csrf
                    <input type="hidden" name="maintenance_confirmation" value="">
                    <input type="hidden" name="maintenance_2fa_code" value="">
                    <button class="btn btn-secondary" type="submit">Entrar em manutenção agora</button>
                </form>
                <form method="POST" action="{{ route('updater.maintenance.off') }}" data-maintenance-form="off">
                    @csrf
                    <input type="hidden" name="maintenance_confirmation" value="">
                    <input type="hidden" name="maintenance_2fa_code" value="">
                    <button class="btn btn-ghost" type="submit">Sair da manutenção agora</button>
                </form>
            @endif
        </div>
    </div>
    <div class="card dashboard-status-card">
        <h3>Status do sistema</h3>
        @php
            $last = (array) ($status['last_run'] ?? []);
            $lastError = json_decode((string) ($last['error_json'] ?? ''), true);
            $safeStatus = \Argws\LaravelUpdater\Support\RunDiagnosticsService::sanitize($status);
        @endphp
        <div class="run-metrics">
            <div><span>Atualizador</span><strong>{{ ($status['enabled'] ?? false) ? 'Ativo' : 'Inativo' }}</strong></div>
            <div><span>Canal</span><strong>{{ $status['channel'] ?? '-' }}</strong></div>
            <div><span>Última execução</span><strong>{{ !empty($last['id']) ? '#' . $last['id'] : '-' }}</strong></div>
            <div><span>Resultado</span><strong>{{ $last['status'] ?? 'Nenhuma' }}</strong></div>
        </div>
        <p class="muted run-revisions">Revisão instalada: <code>{{ $status['revision'] ?? '-' }}</code></p>
        @if(($last['status'] ?? '') === 'failed')
            <div class="run-error-panel">
                <strong>Última atualização falhou</strong>
                <p>{{ \Argws\LaravelUpdater\Support\RunDiagnosticsService::sanitize((string) ($lastError['message'] ?? 'Confira o diagnóstico da execução.')) }}</p>
                @if(!empty($last['id']))
                    <a class="btn btn-action-sm" href="{{ route('updater.runs.show', ['id' => $last['id']]) }}">Ver causa e etapas da execução #{{ $last['id'] }}</a>
                @endif
            </div>
        @endif
        <details class="run-event-details">
            <summary>Visualizar JSON técnico do status</summary>
            <pre>{{ json_encode($safeStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
        </details>
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

<div class="updater-modal" id="maintenance-modal" hidden>
    <div class="updater-modal-backdrop" data-maintenance-close></div>
    <div class="updater-modal-card">
        <h3 id="maintenance-modal-title">Confirmar manutenção</h3>
        <p class="muted" id="maintenance-modal-text">Digite MANTENCAO para confirmar.</p>
        <label>Confirmação
            <input type="text" id="maintenance-confirmation-input" placeholder="MANTENCAO">
        </label>
        <label>Código 2FA (quando aplicável)
            <input type="text" id="maintenance-2fa-input" placeholder="000000 ou recovery code">
        </label>
        <div class="form-inline" style="margin-top:10px;">
            <button type="button" class="btn btn-secondary" data-maintenance-close>Cancelar</button>
            <button type="button" class="btn btn-primary" id="maintenance-confirm-btn">Confirmar ação</button>
        </div>
    </div>
</div>
@endsection
