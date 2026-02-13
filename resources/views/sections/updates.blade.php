@extends('laravel-updater::layout')
@section('page_title', 'Atualizações')
@section('breadcrumbs', 'Atualizações')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Executar atualização</h3>
        <p class="muted">Dispare a atualização manual com o perfil e a fonte ativos no momento.</p>

        <form method="POST" action="{{ route('updater.trigger.update') }}" class="form-grid" style="margin-top:12px;">
            @csrf
            <div>
                <label for="seed">Seeder opcional</label>
                <input id="seed" name="seed" placeholder="Database\Seeders\ExampleSeeder">
            </div>

            <div class="form-inline" style="margin-top:4px;">
                <button class="btn btn-primary" type="submit">Executar atualização agora</button>
                <a class="btn btn-ghost" href="{{ route('updater.section', ['section' => 'runs']) }}">Ver execuções</a>
            </div>
        </form>

        <form method="POST" action="{{ route('updater.trigger.dryrun') }}" style="margin-top:12px;">
            @csrf
            <button class="btn" type="submit">Simular (Dry-run)</button>
        </form>
    </div>

    <div class="card">
        <h3>Contexto atual</h3>
        <p><strong>Perfil ativo:</strong> {{ $activeProfile['name'] ?? 'Não definido' }}</p>
        <p><strong>Fonte ativa:</strong> {{ $activeSource['name'] ?? 'Não definida' }}</p>
        <p><strong>Tipo:</strong> {{ strtoupper((string) ($activeSource['type'] ?? '-')) }}</p>

        @if(empty($activeSource))
            <div class="card card-danger" style="margin-top:10px;">
                <strong>Atenção:</strong>
                <p class="muted" style="margin-top:6px;">Nenhuma fonte de atualização ativa foi encontrada. Configure em <em>Fontes</em> antes de executar.</p>
            </div>
        @endif

        <div class="form-inline" style="margin-top:10px;">
            <a class="btn" href="{{ route('updater.section', ['section' => 'sources']) }}">Gerenciar fontes</a>
            <a class="btn" href="{{ route('updater.section', ['section' => 'profiles']) }}">Gerenciar perfis</a>
        </div>
    </div>
</div>
@endsection
