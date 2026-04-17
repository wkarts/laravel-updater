@extends('laravel-updater::layout')
@section('page_title', 'Execuções')

@section('content')
<div class="card card-compact">
    <h3>Histórico completo</h3>
    <div class="table-wrap">
        <table class="audit-grid-table">
            <thead><tr><th>ID</th><th>Status</th><th>Início</th><th>Fim</th><th>Ações</th></tr></thead>
            <tbody>
            @forelse($runs as $run)
                <tr>
                    <td>#{{ $run['id'] }}</td>
                    <td>{{ $run['status'] }}</td>
                    <td>{{ $run['started_at'] }}</td>
                    <td>{{ $run['finished_at'] ?? '-' }}</td>
                    <td class="col-actions"><a class="btn btn-action-sm" href="{{ route('updater.runs.show', ['id' => $run['id']]) }}">Detalhes</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Sem execuções registradas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
