@extends('laravel-updater::layout')
@section('page_title', 'Runs')
@section('content')<div class="card"><table><thead><tr><th>ID</th><th>Status</th><th>Início</th><th>Fim</th></tr></thead><tbody>@foreach($runs as $run)<tr><td>{{ $run['id'] }}</td><td>{{ $run['status'] }}</td><td>{{ $run['started_at'] }}</td><td>{{ $run['finished_at'] ?? '-' }}</td></tr>@endforeach</tbody></table></div>@endsection
