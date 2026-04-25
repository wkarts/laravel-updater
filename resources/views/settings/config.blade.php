<div class="card updater-runtime-shell">
    <div class="runtime-head">
        <div>
            <h3>Configurações Runtime do Updater</h3>
            <p class="muted">A tela principal de Settings continua centralizando todos os parâmetros do updater. Use <strong>Gerenciar .env</strong> para editar variáveis de ambiente diretamente.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('updater.settings.env.index') }}">Gerenciar .env</a>
    </div>

    @php
        $grouped = [];
        foreach (($fields ?? []) as $row) {
            $grouped[$row['group'] ?? 'Geral'][] = $row;
        }
        ksort($grouped);
    @endphp

    <form method="POST" action="{{ route('updater.settings.config.save') }}" class="runtime-form">
        @csrf

        @foreach($grouped as $groupName => $rows)
            <section class="runtime-group">
                <header class="runtime-group-head">
                    <h4>{{ $groupName }}</h4>
                    <span class="muted">{{ count($rows) }} parâmetro(s)</span>
                </header>

                <div class="runtime-rows">
                    @foreach($rows as $item)
                        @php
                            $field = (string) ($item['field'] ?? '');
                            $label = (string) ($item['label'] ?? $field);
                            $type = (string) ($item['type'] ?? 'string');
                            $isSensitive = (bool) ($item['sensitive'] ?? false);
                        @endphp

                        <div class="runtime-row">
                            <div class="runtime-meta">
                                <label for="{{ $field }}">{{ $label }}</label>
                                <small class="muted"><code>{{ $item['config'] ?? '-' }}</code></small>
                            </div>

                            <div class="runtime-input">
                                @if($type === 'bool')
                                    <label class="runtime-switch-inline" for="{{ $field }}">
                                        <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) ($item['value'] ?? false))>
                                        <span>{{ (bool) ($item['value'] ?? false) ? 'Habilitado' : 'Desabilitado' }}</span>
                                    </label>
                                @elseif($type === 'int')
                                    <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" value="{{ (int) ($item['value'] ?? 0) }}">
                                @elseif($type === 'list')
                                    <textarea id="{{ $field }}" name="{{ $field }}" rows="2" placeholder="Um item por linha">{{ (string) ($item['value'] ?? '') }}</textarea>
                                @elseif($isSensitive)
                                    <input id="{{ $field }}" name="{{ $field }}" type="password" value="" placeholder="•••••••• (deixe vazio para manter)">
                                @else
                                    <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ (string) ($item['value'] ?? '') }}">
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <footer class="runtime-footer">
            <button class="btn btn-primary" type="submit">Salvar runtime</button>
        </footer>
    </form>
</div>

<style>
    .updater-runtime-shell { border-radius:14px; padding:14px; }
    .runtime-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
    .runtime-form { display:flex; flex-direction:column; gap:12px; }
    .runtime-group { border:1px solid var(--line); border-radius:12px; background:var(--surface-soft,#f8fafc); }
    .runtime-group-head { display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid var(--line); }
    .runtime-group-head h4 { margin:0; font-size:1rem; }
    .runtime-rows { display:flex; flex-direction:column; }
    .runtime-row { display:grid; grid-template-columns: minmax(240px, 1fr) minmax(260px, 1.2fr); gap:10px; padding:10px 12px; border-top:1px solid rgba(148,163,184,.2); }
    .runtime-row:first-child { border-top:none; }
    .runtime-meta label { display:block; font-weight:600; margin-bottom:3px; }
    .runtime-input input,
    .runtime-input textarea { width:100%; }
    .runtime-switch-inline { display:flex; align-items:center; gap:8px; min-height:38px; }
    .runtime-footer { display:flex; justify-content:flex-end; margin-top:4px; }
    @media (max-width: 980px) {
        .runtime-row { grid-template-columns: 1fr; }
    }
    @media (max-width: 760px) {
        .runtime-head { flex-direction:column; }
        .runtime-footer .btn { width:100%; }
    }
</style>
