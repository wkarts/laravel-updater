@extends('laravel-updater::layout')
@section('page_title', 'Updates')

@section('content')
<div class="card">
    <h3>Executar atualização</h3>
    <form method="POST" action="{{ route('updater.trigger.update') }}" class="form-grid" style="margin-top:10px;">
        @csrf
        <div>
            <label for="seed">Seeder opcional</label>
            <input id="seed" name="seed" placeholder="Database\Seeders\ExampleSeeder">
        </div>
        <div class="form-inline">
            <button class="btn btn-primary" type="submit">Executar update agora</button>
        </div>
    </form>

    <form method="POST" action="{{ route('updater.trigger.dryrun') }}" style="margin-top:12px;">
        @csrf
        <button class="btn" type="submit">Simular (Dry-run)</button>
    </form>

    <div style="margin-top:10px;" class="muted">
        Perfil ativo: <strong>{{ $activeProfile['name'] ?? '-' }}</strong> ·
        Source ativa: <strong>{{ $activeSource['name'] ?? '-' }}</strong>
    </div>
</div>
@endsection
