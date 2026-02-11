@extends('laravel-updater::layout')
@section('page_title', 'Backups')
@section('content')<div class="card"><h3>Backups</h3><table><thead><tr><th>ID</th><th>Tipo</th><th>Path</th><th>Tamanho</th><th>Data</th></tr></thead><tbody>@foreach($backups as $b)<tr><td>{{ $b['id'] }}</td><td>{{ $b['type'] }}</td><td>{{ $b['path'] }}</td><td>{{ $b['size'] }}</td><td>{{ $b['created_at'] }}</td></tr>@endforeach</tbody></table><p class="muted">Restore com confirmação de senha será suportado sobre drivers compatíveis (Linux e fallback em Windows).</p></div>@endsection
