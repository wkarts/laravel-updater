@extends('laravel-updater::layout')
@section('title', 'Perfis de atualização')
@section('page_title', 'Perfis de atualização')
@section('breadcrumbs', 'Perfis')

@section('content')
<div class="card">
    <div class="form-inline" style="justify-content: space-between; margin-bottom: 10px;">
        <h3>Perfis cadastrados</h3>
        <a class="btn btn-primary" href="{{ route('updater.profiles.create') }}">Novo perfil</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>Backup</th><th>Dry-run</th><th>Retenção</th><th>Ativo</th><th>Ações</th></tr></thead>
            <tbody>
            @forelse($profiles as $profile)
                <tr>
                    <td>{{ $profile['name'] }}</td>
                    <td>{{ (int) $profile['backup_enabled'] === 1 ? 'Sim' : 'Não' }}</td>
                    <td>{{ (int) $profile['dry_run'] === 1 ? 'Sim' : 'Não' }}</td>
                    <td>{{ $profile['retention_backups'] }}</td>
                    <td>{!! (int) $profile['active'] === 1 ? '<span class="badge success">ATIVO</span>' : '<span class="badge">Inativo</span>' !!}</td>
                    <td>
                        <div class="form-inline">
                            <a class="btn btn-secondary" href="{{ route('updater.profiles.edit', $profile['id']) }}">Editar</a>
                            <form method="POST" action="{{ route('updater.profiles.activate', $profile['id']) }}">@csrf <button class="btn btn-ghost" type="submit">Ativar</button></form>
                            <form method="POST" action="{{ route('updater.profiles.delete', $profile['id']) }}" onsubmit="return confirm('Deseja excluir este perfil?')">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Excluir</button></form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nenhum perfil cadastrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
