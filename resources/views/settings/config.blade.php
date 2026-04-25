<div class="card updater-config-card">
    <h3>Configuração Geral (sem depender de .env)</h3>
    <p class="muted">
        Campos marcados como <strong>Travado por .env</strong> estão definidos no arquivo <code>.env</code> e, por segurança,
        não podem ser alterados por aqui.
    </p>

    @php
        $grouped = [];
        foreach (($fields ?? []) as $row) {
            $grouped[$row['group'] ?? 'Geral'][] = $row;
        }
    @endphp

    <form method="POST" action="{{ route('updater.settings.config.save') }}" class="form-grid updater-config-grid" style="margin-top: 10px;">
        @csrf

        @foreach($grouped as $groupName => $rows)
            <div class="updater-config-group-title">
                <strong>{{ $groupName }}</strong>
            </div>

            @foreach($rows as $item)
                @php
                    $isLocked = (bool) ($item['env_locked'] ?? false);
                    $field = (string) ($item['field'] ?? '');
                    $label = (string) ($item['label'] ?? $field);
                    $type = (string) ($item['type'] ?? 'string');
                    $envKey = (string) ($item['env_key'] ?? '');
                    $isSensitive = (bool) ($item['sensitive'] ?? false);
                @endphp

                <div class="updater-config-item">
                    <label for="{{ $field }}">
                        {{ $label }}
                        @if($isLocked)
                            <span class="badge" style="margin-left: 6px; background: #f59e0b; color: #111827;">Travado por .env</span>
                        @endif
                    </label>

                    @if($type === 'bool')
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) ($item['value'] ?? false)) @disabled($isLocked)>
                            <span>{{ (bool) ($item['value'] ?? false) ? 'Habilitado' : 'Desabilitado' }}</span>
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

                    <small class="muted">Config key: <code>{{ $item['config'] ?? '-' }}</code> | Env: <code>{{ $envKey }}</code></small>
                </div>
            @endforeach
        @endforeach

        <div class="form-inline" style="grid-column: 1 / -1; margin-top: 10px;">
            <button class="btn btn-primary" type="submit">Salvar configurações gerais</button>
        </div>
    </form>
</div>

<style>
    .updater-config-card { border-radius: 14px; }
    .updater-config-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(280px, 1fr));
        gap: 12px 14px;
    }
    .updater-config-group-title {
        grid-column: 1 / -1;
        border-top: 1px solid var(--line);
        padding-top: 12px;
        margin-top: 6px;
    }
    .updater-config-item {
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 10px;
        background: var(--surface-soft, #f8fafc);
    }
    .updater-config-item input,
    .updater-config-item textarea {
        width: 100%;
    }
    @media (max-width: 960px) {
        .updater-config-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
