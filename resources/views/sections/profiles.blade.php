@extends('laravel-updater::layout')
@section('page_title', 'Profiles')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Novo profile</h3>
        <form method="POST" action="{{ route('updater.profiles.save') }}" class="form-grid">
            @csrf
            <input name="name" placeholder="Nome do profile" required>
            <input type="number" name="retention_backups" value="10" min="1" max="200" placeholder="Retenção de backups">

            @foreach(['backup_enabled' => 'Backup habilitado', 'dry_run' => 'Dry run', 'force' => 'Forçar operação', 'composer_install' => 'Composer install', 'migrate' => 'Rodar migrate', 'seed' => 'Rodar seed', 'build_assets' => 'Build de assets', 'health_check' => 'Health check', 'rollback_on_fail' => 'Rollback ao falhar', 'active' => 'Marcar como ativo'] as $field => $label)
                <label class="form-inline" style="align-items:center;">
                    <input type="checkbox" name="{{ $field }}" value="1" style="max-width:20px;">
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            <button class="btn btn-primary" type="submit">Salvar profile</button>
        </form>
    </div>

    <div class="card">
        <h3>Profiles cadastrados</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Dry-run</th><th>Ativo</th><th>Retenção</th></tr></thead>
                <tbody>
                @forelse($profiles as $profile)
                    <tr>
                        <td>{{ $profile['name'] }}</td>
                        <td>{{ (int) $profile['dry_run'] === 1 ? 'Sim' : 'Não' }}</td>
                        <td>{{ (int) $profile['active'] === 1 ? 'Sim' : 'Não' }}</td>
                        <td>{{ $profile['retention_backups'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Nenhum profile cadastrado.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
