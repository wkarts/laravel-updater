@extends('laravel-updater::layout')
@section('page_title', 'Profiles')
@section('content')
<div class="grid"><div class="card"><h3>Novo profile</h3><form method="POST" action="{{ route('updater.profiles.save') }}">@csrf
<input name="name" placeholder="Nome" required>
<input type="number" name="retention_backups" value="10" min="1">
@foreach(['backup_enabled','dry_run','force','composer_install','migrate','seed','build_assets','health_check','rollback_on_fail','active'] as $f)
<label><input type="checkbox" name="{{ $f }}" value="1"> {{ $f }}</label><br>
@endforeach
<button type="submit">Salvar</button></form></div>
<div class="card"><h3>Profiles cadastradas</h3><table><thead><tr><th>Nome</th><th>Dry-run</th><th>Ativa</th></tr></thead><tbody>@foreach($profiles as $profile)<tr><td>{{ $profile['name'] }}</td><td>{{ (int)$profile['dry_run']===1?'Sim':'Não' }}</td><td>{{ (int)$profile['active']===1?'Sim':'Não' }}</td></tr>@endforeach</tbody></table></div></div>
@endsection
