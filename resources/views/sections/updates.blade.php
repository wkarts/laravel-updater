@extends('laravel-updater::layout')
@section('page_title', 'Updates')
@section('content')
<div class="card"><h3>Executar update</h3>
<form method="POST" action="{{ route('updater.trigger.update') }}">@csrf
<input name="seed" placeholder="Seeder opcional">
<label><input type="checkbox" name="check_only" value="1"> Check-only</label>
<button type="submit">Executar update agora</button>
</form>
<p class="muted">Profile ativa: {{ $activeProfile['name'] ?? '-' }} | Source ativa: {{ $activeSource['name'] ?? '-' }}</p>
</div>
@endsection
