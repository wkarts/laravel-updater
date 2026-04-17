@extends('laravel-updater::layout')
@section('page_title', 'Auditoria de Migrations')

@section('content')
<div class="card card-compact">
    <h3>Dashboard de auditoria</h3>
    <div class="update-status-grid audit-dashboard-grid">
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

<div class="card card-compact" style="margin-top:10px;">
    <h3>Filtros</h3>
    <form method="GET" action="{{ route('updater.migrations.index') }}" class="form-grid audit-filters compact-form" style="margin-top:4px;">
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
            <label class="switch-inline">
                <input type="checkbox" name="inconsistent" value="1" {{ !empty($filters['inconsistent']) ? 'checked' : '' }}>
                Somente inconsistentes
            </label>
        </div>
        <div class="audit-filter-options">
            <label class="switch-inline">
                <input type="checkbox" value="1" data-toggle-file-path>
                Exibir caminho completo abaixo do nome da migration
            </label>
        </div>
        <div class="form-inline audit-filter-actions compact-actions">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn" href="{{ route('updater.migrations.index') }}">Limpar</a>
        </div>
    </form>
</div>

<div class="card card-compact" style="margin-top:10px;">
    <h3>Grid de auditoria</h3>
    <div class="table-wrap migrations-grid-wrap" data-migrations-grid>
        <table>
            <thead>
            <tr>
                <th>Migration</th>
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
                    <td>
                        <code>{{ $row['migration'] }}</code>
                        <small class="migration-file-path muted">{{ $row['file_path'] ?? '-' }}</small>
                    </td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ !empty($row['executed']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ !empty($row['has_error']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ !empty($row['reconciled']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ !empty($row['reapplied']) ? 'SIM' : 'NÃO' }}</td>
                    <td>{{ (int) ($row['attempt_count'] ?? 0) }}</td>
                    <td>{{ $row['last_execution_at'] ?? '-' }}</td>
                    <td>{{ !empty($row['last_run_id']) ? '#' . (int) $row['last_run_id'] : '-' }}</td>
                    <td class="actions-cell">
                        <form method="POST" action="{{ route('updater.migrations.reapply') }}" class="migration-actions-row">
                            @csrf
                            <input type="hidden" name="migration" value="{{ $row['migration'] }}">
                            <input type="hidden" name="redirect_to" value="index">
                            <input type="hidden" name="reason" value="" data-reason-value>

                            <a class="btn btn-secondary hint-action btn-action-sm" title="Visualizar detalhes consolidados da migration" href="{{ route('updater.migrations.show', ['migration' => $row['migration']]) }}">👁</a>
                            <a class="btn btn-secondary hint-action btn-action-sm" title="Abrir histórico completo de tentativas" href="{{ route('updater.migrations.show', ['migration' => $row['migration']]) }}#historico">🕘</a>
                            <a class="btn btn-secondary hint-action btn-action-sm" title="Filtrar viewer de logs por esta migration" href="{{ route('updater.section', ['section' => 'logs']) }}?q={{ urlencode($row['migration']) }}">📜</a>
                            <a class="btn btn-secondary hint-action btn-action-sm" title="Analisar consistência código x banco x histórico" href="{{ route('updater.migrations.show', ['migration' => $row['migration']]) }}#consistencia">🧪</a>

                            <button class="btn btn-secondary hint-action btn-action-sm btn-action-icon" type="button" data-open-reason title="Definir motivo da reaplicação" aria-label="Definir motivo da reaplicação">✎</button>
                            <button class="btn hint-action btn-action-sm btn-action-icon" title="Registrar na fila para execução posterior" aria-label="Marcar para reaplicação" type="submit" name="action_type" value="queue">⏳</button>
                            <button class="btn btn-primary hint-action btn-action-sm btn-action-icon" title="Executar reaplicação desta migration agora (modo idempotente)" aria-label="Reaplicar migration agora" type="submit" name="action_type" value="run_now">↻</button>
                        </form>

                        <dialog class="reason-dialog" data-reason-dialog>
                            <form method="dialog" class="reason-dialog-form">
                                <h4>Motivo da reaplicação</h4>
                                <input type="text" data-reason-input placeholder="Ex.: corrigir inconsistência detectada">
                                <div class="reason-dialog-actions">
                                    <button type="button" class="btn btn-secondary btn-action-sm" data-clear-reason>Limpar</button>
                                    <button type="button" class="btn btn-secondary btn-action-sm" data-close-reason>Cancelar</button>
                                    <button type="button" class="btn btn-primary btn-action-sm" data-save-reason>Salvar</button>
                                </div>
                            </form>
                        </dialog>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="muted">Nenhuma migration encontrada para os filtros aplicados.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@once
    <script>
        (() => {
            const getRowContext = (target) => {
                const actionsRow = target.closest('.migration-actions-row');
                if (!actionsRow) {
                    return null;
                }

                const cell = actionsRow.closest('.actions-cell');
                const dialog = cell ? cell.querySelector('[data-reason-dialog]') : null;
                const hiddenReason = actionsRow.querySelector('[data-reason-value]');
                const input = dialog ? dialog.querySelector('[data-reason-input]') : null;

                if (!dialog || !hiddenReason || !input) {
                    return null;
                }

                return { dialog, hiddenReason, input };
            };

            const gridWrap = document.querySelector('[data-migrations-grid]');
            const filePathToggle = document.querySelector('[data-toggle-file-path]');
            const filePathStorageKey = 'updater:migrations:show-file-path';

            const applyFilePathPreference = (enabled) => {
                if (gridWrap) {
                    gridWrap.classList.toggle('show-file-path', enabled);
                }
                if (filePathToggle) {
                    filePathToggle.checked = enabled;
                }
            };

            if (filePathToggle) {
                const storedPreference = localStorage.getItem(filePathStorageKey);
                applyFilePathPreference(storedPreference === '1');

                filePathToggle.addEventListener('change', () => {
                    const enabled = filePathToggle.checked;
                    localStorage.setItem(filePathStorageKey, enabled ? '1' : '0');
                    applyFilePathPreference(enabled);
                });
            }

            document.addEventListener('click', (event) => {
                const openButton = event.target.closest('[data-open-reason]');
                if (openButton) {
                    const context = getRowContext(openButton);
                    if (!context) {
                        return;
                    }

                    context.input.value = context.hiddenReason.value || '';
                    context.dialog.showModal();
                    context.input.focus();
                    context.input.select();
                    return;
                }

                const saveButton = event.target.closest('[data-save-reason]');
                if (saveButton) {
                    const dialog = saveButton.closest('[data-reason-dialog]');
                    if (!dialog) {
                        return;
                    }

                    const cell = dialog.closest('.actions-cell');
                    const actionsRow = cell ? cell.querySelector('.migration-actions-row') : null;
                    const hiddenReason = actionsRow ? actionsRow.querySelector('[data-reason-value]') : null;
                    const input = dialog.querySelector('[data-reason-input]');
                    if (hiddenReason && input) {
                        hiddenReason.value = input.value.trim();
                    }
                    dialog.close();
                    return;
                }

                const clearButton = event.target.closest('[data-clear-reason]');
                if (clearButton) {
                    const dialog = clearButton.closest('[data-reason-dialog]');
                    if (!dialog) {
                        return;
                    }

                    const input = dialog.querySelector('[data-reason-input]');
                    if (input) {
                        input.value = '';
                        input.focus();
                    }
                    return;
                }

                const closeButton = event.target.closest('[data-close-reason]');
                if (closeButton) {
                    const dialog = closeButton.closest('[data-reason-dialog]');
                    if (dialog) {
                        dialog.close();
                    }
                }
            });
        })();
    </script>
@endonce
