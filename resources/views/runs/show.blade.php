@extends('laravel-updater::layout')
@section('page_title', 'Detalhes da execução')

@section('content')
<div class="card">
    <h3>Execução #{{ $run['id'] }}</h3>
    <p><strong>Status:</strong> {{ $run['status'] }}</p>
    <p><strong>Início:</strong> {{ $run['started_at'] }}</p>
    <p><strong>Fim:</strong> {{ $run['finished_at'] ?? '-' }}</p>
    <p><strong>Revisão anterior:</strong> {{ $run['revision_before'] ?? '-' }}</p>
    <p><strong>Revisão posterior:</strong> {{ $run['revision_after'] ?? '-' }}</p>

    @if(!empty($run['options_json']))
        <h4>Plano/Opções</h4>
        <pre>{{ $run['options_json'] }}</pre>
    @endif

    @if(($run['status'] ?? '') === 'DRY_RUN' && ($pendingApproval ?? false))
        <hr style="margin:12px 0;">
        <h4>Aprovar e executar atualização real</h4>
        <form method="POST" action="{{ route('updater.runs.approve', ['id' => $run['id']]) }}" class="form-grid">
            @csrf
            <div>
                <label for="password">Senha do administrador</label>
                <input id="password" name="password" type="password" required>
            </div>
            <div class="form-inline">
                <button class="btn btn-primary" type="submit">Aprovar e executar</button>
            </div>
        </form>
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
