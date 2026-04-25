@extends('laravel-updater::layout')
@section('title', 'Parâmetros .env')
@section('page_title', 'Parâmetros .env do Updater')
@section('breadcrumbs', 'Configurações / .env')

@section('content')
<div class="env-settings-stack">
    <div class="card env-settings-header">
        <div>
            <h3>Gerenciamento de parâmetros .env</h3>
            <p class="muted">Edite com segurança apenas chaves relacionadas ao updater. Após salvar, execute <code>php artisan config:clear</code>.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('updater.settings.index') }}">Voltar para Configurações</a>
    </div>

    @php
        $grouped = [];
        foreach (($envFields ?? []) as $row) {
            $grouped[$row['group'] ?? 'Geral'][] = $row;
        }
        ksort($grouped);
    @endphp

    <form method="POST" action="{{ route('updater.settings.env.save') }}" class="card env-settings-form">
        @csrf

        @foreach($grouped as $groupName => $rows)
            <section class="env-group">
                <header class="env-group-head">
                    <h4>{{ $groupName }}</h4>
                    <span class="muted">{{ count($rows) }} chave(s)</span>
                </header>

                <div class="env-grid">
                    @foreach($rows as $item)
                        @php
                            $field = (string) ($item['field'] ?? '');
                            $label = (string) ($item['label'] ?? $field);
                            $type = (string) ($item['type'] ?? 'string');
                            $isSensitive = (bool) ($item['sensitive'] ?? false);
                            $envKey = (string) ($item['primary_env_key'] ?? '');
                            $envValue = (string) ($item['env_value'] ?? '');
                        @endphp

                        <article class="env-field-card">
                            <label for="{{ $field }}" class="env-field-label">{{ $label }}</label>

                            @if($type === 'bool')
                                <label class="env-switch-row" for="{{ $field }}">
                                    <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked(strtolower($envValue) === 'true' || $envValue === '1')>
                                    <span>{{ strtolower($envValue) === 'true' || $envValue === '1' ? 'true' : 'false' }}</span>
                                </label>
                            @elseif($type === 'list')
                                <textarea id="{{ $field }}" name="{{ $field }}" rows="3" placeholder="Um item por linha">{{ str_replace(',', "\n", $envValue) }}</textarea>
                            @elseif($isSensitive)
                                <input id="{{ $field }}" name="{{ $field }}" type="password" value="" placeholder="•••••••• (deixe vazio para manter)">
                            @else
                                <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $envValue }}">
                            @endif

                            <p class="muted env-field-hint"><strong>{{ $envKey }}</strong></p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="env-form-footer">
            <button class="btn btn-primary" type="submit">Salvar .env</button>
        </div>
    </form>
</div>

<style>
    .env-settings-stack { display:flex; flex-direction:column; gap:12px; }
    .env-settings-header { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .env-settings-form { display:flex; flex-direction:column; gap:12px; }
    .env-group { border:1px solid var(--line); border-radius:12px; padding:12px; }
    .env-group-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
    .env-group-head h4 { margin:0; font-size:1rem; }
    .env-grid { display:grid; grid-template-columns: repeat(2, minmax(280px, 1fr)); gap:10px; }
    .env-field-card { border:1px solid var(--line); border-radius:10px; padding:10px; background:var(--surface-soft, #f8fafc); display:flex; flex-direction:column; gap:8px; }
    .env-field-label { font-weight:600; }
    .env-field-card input,
    .env-field-card textarea { width:100%; }
    .env-switch-row { display:flex; align-items:center; gap:8px; }
    .env-field-hint { margin:0; font-size:.82rem; }
    .env-form-footer { display:flex; justify-content:flex-end; }

    @media (max-width: 1080px) {
        .env-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
        .env-settings-header { flex-direction:column; }
        .env-form-footer .btn { width:100%; }
    }
</style>
@endsection
