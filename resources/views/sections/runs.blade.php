@extends('laravel-updater::layout')
@section('page_title', 'Execuções')

@section('content')
@php
    // Rotas recém-incluídas podem não existir no route:cache do host após
    // troca de versão. Nunca derrubar a listagem por um link opcional.
    $runDetailsAvailable = \Illuminate\Support\Facades\Route::has('updater.runs.show');
    $diagnosticsDownloadAvailable = \Illuminate\Support\Facades\Route::has('updater.runs.diagnostics.download');
@endphp
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
                    <td class="col-actions">
                        @if($runDetailsAvailable)
                            <a class="btn btn-action-sm" href="{{ route('updater.runs.show', ['id' => $run['id']]) }}">Detalhes</a>
                        @else
                            <span class="muted" title="Rota de detalhes indisponível no cache atual da aplicação.">Detalhes indisponíveis</span>
                        @endif
                        @if($diagnosticsDownloadAvailable)
                            <a class="btn btn-action-sm" href="{{ route('updater.runs.diagnostics.download', ['id' => $run['id'], 'format' => 'json']) }}">JSON</a>
                            <a class="btn btn-action-sm" href="{{ route('updater.runs.diagnostics.download', ['id' => $run['id'], 'format' => 'zip']) }}">ZIP</a>
                        @elseif($runDetailsAvailable)
                            {{-- Usa apenas a rota antiga (de detalhes) se o cache ainda não possuir a nova. --}}
                            <a class="btn btn-action-sm" href="{{ route('updater.runs.show', ['id' => $run['id'], 'download' => 'json']) }}">JSON</a>
                            <a class="btn btn-action-sm" href="{{ route('updater.runs.show', ['id' => $run['id'], 'download' => 'zip']) }}">ZIP</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Sem execuções registradas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
