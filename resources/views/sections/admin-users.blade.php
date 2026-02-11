@extends('laravel-updater::layout')
@section('page_title', 'Admin Users')

@section('content')
<div class="card">
    <h3>Usuários administrativos</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Email</th><th>Nome</th><th>Admin</th><th>Ativo</th><th>Último login</th></tr></thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td>#{{ $user['id'] }}</td>
                    <td>{{ $user['email'] }}</td>
                    <td>{{ $user['name'] ?? '-' }}</td>
                    <td>{{ (int) $user['is_admin'] === 1 ? 'Sim' : 'Não' }}</td>
                    <td>{{ (int) $user['is_active'] === 1 ? 'Sim' : 'Não' }}</td>
                    <td>{{ $user['last_login_at'] ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nenhum usuário encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
