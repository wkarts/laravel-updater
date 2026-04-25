<div class="card updater-config-shell">
    <div class="updater-config-header">
        <div>
            <h3>Parâmetros do Updater</h3>
            <p class="muted">Organizados por categoria, com prioridade para <code>.env</code>. Campos travados estão definidos no ambiente.</p>
        </div>
        <div class="updater-config-actions">
            <a class="btn btn-secondary" href="{{ route('updater.settings.env.index') }}">Gerenciar .env</a>
        </div>
    </div>

    @php
        $grouped = [];
        foreach (($fields ?? []) as $row) {
            $grouped[$row['group'] ?? 'Geral'][] = $row;
        }
        ksort($grouped);
    @endphp

    <form method="POST" action="{{ route('updater.settings.config.save') }}" class="updater-config-form">
        @csrf

        @foreach($grouped as $groupName => $rows)
            <section class="updater-config-group" aria-label="Grupo {{ $groupName }}">
                <header class="updater-config-group-head">
                    <h4>{{ $groupName }}</h4>
                    <span class="muted">{{ count($rows) }} parâmetro(s)</span>
                </header>

                <div class="updater-config-grid">
                    @foreach($rows as $item)
                        @php
                            $isLocked = (bool) ($item['env_locked'] ?? false);
                            $field = (string) ($item['field'] ?? '');
                            $label = (string) ($item['label'] ?? $field);
                            $type = (string) ($item['type'] ?? 'string');
                            $envKey = (string) ($item['env_key'] ?? '');
                            $isSensitive = (bool) ($item['sensitive'] ?? false);
                        @endphp

                        <article class="updater-field-card {{ $isLocked ? 'is-locked' : '' }}">
                            <div class="updater-field-top">
                                <label for="{{ $field }}" class="updater-field-label">{{ $label }}</label>
                                @if($isLocked)
                                    <span class="badge updater-lock-badge">Travado por .env</span>
                                @endif
                            </div>

                            @if($type === 'bool')
                                <label class="updater-switch-row" for="{{ $field }}">
                                    <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) ($item['value'] ?? false)) @disabled($isLocked)>
                                    <span class="updater-switch-state">{{ (bool) ($item['value'] ?? false) ? 'Habilitado' : 'Desabilitado' }}</span>
                                </label>
                            @elseif($type === 'int')
                                <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" value="{{ (int) ($item['value'] ?? 0) }}" @disabled($isLocked)>
                            @elseif($type === 'list')
                                <textarea id="{{ $field }}" name="{{ $field }}" rows="3" @disabled($isLocked) placeholder="Um item por linha">{{ (string) ($item['value'] ?? '') }}</textarea>
                            @elseif($isSensitive)
                                <input id="{{ $field }}" name="{{ $field }}" type="password" value="" placeholder="•••••••• (deixe vazio para manter)" @disabled($isLocked)>
                            @else
                                <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ (string) ($item['value'] ?? '') }}" @disabled($isLocked)>
                            @endif

                            <p class="updater-field-hint muted">
                                <span><strong>Config:</strong> <code>{{ $item['config'] ?? '-' }}</code></span>
                                <span><strong>Env:</strong> <code>{{ $envKey }}</code></span>
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="updater-config-footer">
            <button class="btn btn-primary" type="submit">Salvar configurações em runtime</button>
        </div>
    </form>
</div>

<style>
    .updater-config-shell { padding: 14px; border-radius: 14px; }
    .updater-config-header { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom: 14px; }
    .updater-config-actions { display:flex; gap:8px; flex-wrap: wrap; }
    .updater-config-form { display:flex; flex-direction:column; gap:14px; }
    .updater-config-group { border:1px solid var(--line); border-radius:12px; padding:12px; background: var(--surface-soft, #f8fafc); }
    .updater-config-group-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
    .updater-config-group-head h4 { margin:0; font-size: 1rem; }
    .updater-config-grid { display:grid; grid-template-columns: repeat(2, minmax(280px, 1fr)); gap:12px; }
    .updater-field-card { border:1px solid var(--line); border-radius:10px; background:#fff; padding:10px; display:flex; flex-direction:column; gap:8px; }
    .updater-field-card.is-locked { background: #f9fafb; }
    .updater-field-top { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; }
    .updater-field-label { font-weight:600; margin:0; }
    .updater-lock-badge { background:#f59e0b; color:#111827; }
    .updater-switch-row { display:flex; align-items:center; gap:8px; margin:0; }
    .updater-switch-state { font-size:.92rem; color: var(--muted, #64748b); }
    .updater-field-hint { margin:0; display:flex; flex-direction:column; gap:2px; font-size:.82rem; }
    .updater-config-footer { display:flex; justify-content:flex-end; margin-top:6px; }
    .updater-field-card input,
    .updater-field-card textarea { width:100%; }

    @media (max-width: 1180px) {
        .updater-config-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
        .updater-config-header { flex-direction:column; }
        .updater-config-footer { justify-content:stretch; }
        .updater-config-footer .btn { width:100%; }
    }
</style>
