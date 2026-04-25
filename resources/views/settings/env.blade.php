@extends('laravel-updater::layout')
@section('title', 'Parâmetros .env')
@section('page_title', 'Parâmetros .env do Updater')
@section('breadcrumbs', 'Configurações / .env')

@section('content')
<div class="env-shell">
    <div class="card env-head">
        <div>
            <h3>Gerenciar parâmetros de ambiente</h3>
            <p class="muted">Edite somente variáveis do updater. Esta tela é a fonte exclusiva para parâmetros <code>.env</code>.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('updater.settings.index') }}">Voltar</a>
    </div>

    @php
        $grouped = [];
        foreach (($envFields ?? []) as $row) {
            $grouped[$row['group'] ?? 'Geral'][] = $row;
        }
        ksort($grouped);
    @endphp

    <form method="POST" action="{{ route('updater.settings.env.save') }}" class="card env-form">
        @csrf

        @foreach($grouped as $groupName => $rows)
            <section class="env-group">
                <header class="env-group-head">
                    <h4>{{ $groupName }}</h4>
                    <span class="muted">{{ count($rows) }} chave(s)</span>
                </header>

                <div class="env-rows">
                    @foreach($rows as $item)
                        @php
                            $field = (string) ($item['field'] ?? '');
                            $label = (string) ($item['label'] ?? $field);
                            $type = (string) ($item['type'] ?? 'string');
                            $isSensitive = (bool) ($item['sensitive'] ?? false);
                            $envKey = (string) ($item['primary_env_key'] ?? '');
                            $envValue = (string) ($item['env_value'] ?? '');
                        @endphp

                        <div class="env-row">
                            <div class="env-meta">
                                <label for="{{ $field }}">{{ $label }}</label>
                                <small class="muted"><code>{{ $envKey }}</code></small>
                            </div>

                            <div class="env-input">
                                @if($type === 'bool')
                                    <label class="env-switch-inline" for="{{ $field }}">
                                        <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked(strtolower($envValue) === 'true' || $envValue === '1')>
                                        <span>{{ strtolower($envValue) === 'true' || $envValue === '1' ? 'true' : 'false' }}</span>
                                    </label>
                                @elseif($type === 'list')
                                    <textarea id="{{ $field }}" name="{{ $field }}" rows="2" placeholder="Um item por linha">{{ str_replace(',', "\n", $envValue) }}</textarea>
                                @elseif($isSensitive)
                                    <input id="{{ $field }}" name="{{ $field }}" type="password" value="" placeholder="•••••••• (vazio mantém valor)">
                                @else
                                    <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $envValue }}">
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <footer class="env-footer">
            <button class="btn btn-primary" type="submit">Salvar .env</button>
        </footer>
    </form>
</div>

<style>
    .env-shell { display:flex; flex-direction:column; gap:12px; }
    .env-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .env-form { padding:12px; display:flex; flex-direction:column; gap:12px; }
    .env-group { border:1px solid var(--line); border-radius:12px; background:var(--surface-soft,#f8fafc); }
    .env-group-head { display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid var(--line); }
    .env-group-head h4 { margin:0; font-size:1rem; }
    .env-rows { display:flex; flex-direction:column; }
    .env-row { display:grid; grid-template-columns: minmax(240px,1fr) minmax(260px,1.2fr); gap:10px; padding:10px 12px; border-top:1px solid rgba(148,163,184,.2); }
    .env-row:first-child { border-top:none; }
    .env-meta label { display:block; font-weight:600; margin-bottom:3px; }
    .env-input input,
    .env-input textarea { width:100%; }
    .env-switch-inline { display:flex; align-items:center; gap:8px; min-height:38px; }
    .env-footer { display:flex; justify-content:flex-end; margin-top:4px; }

    @media (max-width: 980px) {
        .env-row { grid-template-columns: 1fr; }
    }
    @media (max-width: 760px) {
        .env-head { flex-direction:column; }
        .env-footer .btn { width:100%; }
    }
</style>
@endsection
