@extends('laravel-updater::layout')
@section('page_title', 'Runs')

@section('content')
<div class="card">
    <h3>Histórico completo</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Status</th><th>Início</th><th>Fim</th></tr></thead>
            <tbody>
            @forelse($runs as $run)
                <tr>
                    <td>#{{ $run['id'] }}</td>
                    <td>{{ $run['status'] }}</td>
                    <td>{{ $run['started_at'] }}</td>
                    <td>{{ $run['finished_at'] ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Sem execuções registradas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
