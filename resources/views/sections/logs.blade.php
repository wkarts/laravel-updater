@extends('laravel-updater::layout')
@section('page_title', 'Logs')

@section('content')
<div class="card card-compact">
    <h3>Viewer de logs</h3>

    <form method="GET" class="form-inline compact-actions" style="margin:6px 0 8px;">
        <input name="run_id" placeholder="Run ID" value="{{ request('run_id') }}">
        <select name="level">
            <option value="">Todos os níveis</option>
            @foreach(['debug','info','warn','error'] as $level)
                <option value="{{ $level }}" @selected(request('level') === $level)>{{ strtoupper($level) }}</option>
            @endforeach
        </select>
        <input name="q" placeholder="Buscar mensagem" value="{{ request('q') }}">
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>

    <div class="table-wrap">
        <table class="audit-grid-table">
            <thead><tr><th>Data</th><th>Nível</th><th class="col-main">Mensagem</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log['created_at'] }}</td>
                    <td>{{ strtoupper($log['level']) }}</td>
                    <td class="col-main">{{ $log['message'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Sem logs para os filtros selecionados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
