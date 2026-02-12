@extends('laravel-updater::layout')
@section('page_title', 'Detalhes da execução')

@section('content')
<div class="card">
    <h3>Run #{{ $run['id'] }}</h3>
    <p><strong>Status:</strong> {{ $run['status'] }}</p>
    <p><strong>Início:</strong> {{ $run['started_at'] }}</p>
    <p><strong>Fim:</strong> {{ $run['finished_at'] ?? '-' }}</p>
    <p><strong>Revision before:</strong> {{ $run['revision_before'] ?? '-' }}</p>
    <p><strong>Revision after:</strong> {{ $run['revision_after'] ?? '-' }}</p>

    @if(!empty($run['options_json']))
        <h4>Plano/Opções</h4>
        <pre>{{ $run['options_json'] }}</pre>
    @endif
</div>

<div class="card">
    <h3>Logs</h3>
    <ul>
    @forelse($logs as $log)
        <li>[{{ $log['level'] }}] {{ $log['message'] }}</li>
    @empty
        <li class="muted">Sem logs para esta execução.</li>
    @endforelse
    </ul>
</div>
@endsection
