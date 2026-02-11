@extends('laravel-updater::layout')
@section('page_title', 'Settings')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Branding White-label</h3>
        <form method="POST" action="{{ route('updater.settings.branding.save') }}" enctype="multipart/form-data" class="form-grid" style="margin-top:10px;">
            @csrf
            <div>
                <label for="app_name">Nome</label>
                <input id="app_name" name="app_name" value="{{ $branding['app_name'] ?? '' }}" placeholder="Nome principal">
            </div>

            <div>
                <label for="app_sufix_name">Sufixo</label>
                <input id="app_sufix_name" name="app_sufix_name" value="{{ $branding['app_sufix_name'] ?? '' }}" placeholder="Sufixo opcional">
            </div>

            <div>
                <label for="app_desc">Descrição</label>
                <input id="app_desc" name="app_desc" value="{{ $branding['app_desc'] ?? '' }}" placeholder="Descrição curta">
            </div>

            <div>
                <label for="primary_color">Cor primária</label>
                <input id="primary_color" name="primary_color" value="{{ $branding['primary_color'] ?? '#3b82f6' }}" placeholder="#3b82f6">
            </div>

            <div>
                <label for="logo">Logo (png/jpg/jpeg/svg)</label>
                <input id="logo" type="file" name="logo">
            </div>

            <div>
                <label for="favicon">Favicon (ico/png)</label>
                <input id="favicon" type="file" name="favicon">
            </div>

            <div class="form-inline">
                <button class="btn btn-primary" type="submit">Salvar branding</button>
            </div>
        </form>

        <form method="POST" action="{{ route('updater.settings.branding.reset') }}" style="margin-top:10px;">
            @csrf
            <button class="btn btn-secondary" type="submit">Resetar para ENV</button>
        </form>
    </div>

    <div class="card">
        <h3>Preview ENV</h3>
        <p class="muted">UPDATER_APP_NAME={{ config('updater.app.name') }}</p>
        <p class="muted">UPDATER_APP_SUFIX_NAME={{ config('updater.app.sufix_name') }}</p>
        <p class="muted">UPDATER_APP_DESC={{ config('updater.app.desc') }}</p>
    </div>
</div>
@endsection
