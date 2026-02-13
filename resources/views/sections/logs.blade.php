@extends('laravel-updater::layout')
@section('page_title', 'Logs')

@section('content')
<div class="card">
    <h3>Viewer de logs</h3>

    <form method="GET" class="form-inline" style="margin:10px 0 14px;">
        <input name="run_id" placeholder="Run ID" value="{{ request('run_id') }}">
        <select name="level">
            <option value="">Todos os níveis</option>
            @foreach(['debug','info','warn','error'] as $level)
                <option value="{{ $level }}" @selected(request('level') === $level)>{{ strtoupper($level) }}</option>
            @endforeach
        </select>
        <input name="q" placeholder="Buscar mensagem" value="{{ request('q') }}">
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <a class="btn" href="{{ route('updater.logs.report.download', ['run_id' => request('run_id'), 'level' => request('level'), 'q' => request('q')]) }}">Baixar relatório filtrado</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Data</th><th>Nível</th><th>Mensagem</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log['created_at'] }}</td>
                    <td>{{ strtoupper($log['level']) }}</td>
                    <td>{{ $log['message'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Sem logs para os filtros selecionados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
