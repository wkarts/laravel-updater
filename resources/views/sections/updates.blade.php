@extends('laravel-updater::layout')
@section('page_title', 'Updates')

@section('content')
<div class="card">
    <h3>Executar atualização</h3>
    <p class="muted">Use esta tela para disparar updates imediatos com opções operacionais.</p>

    <form method="POST" action="{{ route('updater.trigger.update') }}" class="form-grid" style="margin-top:10px;">
        @csrf
        <div>
            <label for="seed">Seeder opcional</label>
            <input id="seed" name="seed" placeholder="Database\\Seeders\\ExampleSeeder">
        </div>

        <label class="form-inline" style="align-items:center;">
            <input type="checkbox" name="check_only" value="1" style="max-width:20px;">
            <span>Executar em modo check-only</span>
        </label>

        <div class="form-inline">
            <button class="btn btn-primary" type="submit">Executar update agora</button>
        </div>
    </form>

    <div style="margin-top:10px;" class="muted">
        Profile ativa: <strong>{{ $activeProfile['name'] ?? '-' }}</strong> ·
        Source ativa: <strong>{{ $activeSource['name'] ?? '-' }}</strong>
    </div>
</div>
@endsection
