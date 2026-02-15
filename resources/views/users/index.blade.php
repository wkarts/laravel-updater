@extends('laravel-updater::layout')
@section('title', 'Usuários')
@section('page_title', 'Usuários')
@section('breadcrumbs', 'Usuários')

@section('content')
<div class="card">
    <div class="form-inline" style="justify-content: space-between; margin-bottom: 10px;">
        <h3>Usuários administrativos</h3>
        <a class="btn btn-primary" href="{{ route('updater.users.create') }}">Novo usuário</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>E-mail</th><th>Admin?</th><th>Ativo?</th><th>2FA?</th><th>Permissões</th><th>Último login</th><th>Ações</th></tr></thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td>{{ $user['name'] ?? '-' }}</td>
                    <td>{{ $user['email'] }}</td>
                    <td>{{ (int) $user['is_admin'] === 1 ? 'Sim' : 'Não' }}</td>
                    <td>{{ (int) $user['is_active'] === 1 ? 'Sim' : 'Não' }}</td>
                    <td>{{ (int) $user['totp_enabled'] === 1 ? 'Ativo' : 'Inativo' }}</td>
                    <td>
                        @php($perms = json_decode((string) ($user['permissions_json'] ?? '[]'), true))
                        {{ is_array($perms) ? count($perms) : 0 }}
                    </td>
                    <td>{{ $user['last_login_at'] ?? '-' }}</td>
                    <td>
                        <div class="form-inline">
                            <a class="btn btn-secondary" href="{{ route('updater.users.edit', $user['id']) }}">Editar</a>
                            <form method="POST" action="{{ route('updater.users.delete', $user['id']) }}" onsubmit="return confirm('Deseja remover este usuário?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger" type="submit">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Nenhum usuário encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
