@extends('laravel-updater::layout')
@section('page_title', 'Admin Users')
@section('content')<div class="card"><h3>Usuários administrativos</h3><table><thead><tr><th>ID</th><th>Email</th><th>Nome</th><th>Admin</th><th>Ativo</th><th>Último login</th></tr></thead><tbody>@foreach($users as $u)<tr><td>{{ $u['id'] }}</td><td>{{ $u['email'] }}</td><td>{{ $u['name'] ?? '-' }}</td><td>{{ (int)$u['is_admin']===1?'Sim':'Não' }}</td><td>{{ (int)$u['is_active']===1?'Sim':'Não' }}</td><td>{{ $u['last_login_at'] ?? '-' }}</td></tr>@endforeach</tbody></table></div>@endsection
