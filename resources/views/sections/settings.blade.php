@extends('laravel-updater::layout')
@section('page_title', 'Settings')
@section('content')
<div class="grid"><div class="card"><h3>Branding</h3><form method="POST" action="{{ route('updater.settings.branding.save') }}" enctype="multipart/form-data">@csrf
<input name="app_name" placeholder="Nome" value="{{ $branding['app_name'] ?? '' }}">
<input name="app_sufix_name" placeholder="Sufixo" value="{{ $branding['app_sufix_name'] ?? '' }}">
<input name="app_desc" placeholder="Descrição" value="{{ $branding['app_desc'] ?? '' }}">
<input name="primary_color" placeholder="#3b82f6" value="{{ $branding['primary_color'] ?? '#3b82f6' }}">
<label>Logo<input type="file" name="logo"></label>
<label>Favicon<input type="file" name="favicon"></label>
<button type="submit">Salvar</button></form>
<form method="POST" action="{{ route('updater.settings.branding.reset') }}" style="margin-top:8px">@csrf <button class="secondary">Resetar para ENV</button></form>
</div>
<div class="card"><h3>Preview ENV</h3><p class="muted">UPDATER_APP_NAME={{ config('updater.app.name') }}</p><p class="muted">UPDATER_APP_SUFIX_NAME={{ config('updater.app.sufix_name') }}</p><p class="muted">UPDATER_APP_DESC={{ config('updater.app.desc') }}</p></div></div>
@endsection
