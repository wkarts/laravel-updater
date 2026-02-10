<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Updater</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f8fafc; color: #111827; }
        .wrap { max-width: 1000px; margin: auto; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        button { border: 0; padding: 10px 14px; border-radius: 6px; cursor: pointer; background: #2563eb; color: #fff; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        .status { font-weight: bold; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2>Laravel Updater</h2>
        @if (session('status'))
            <p>{{ session('status') }}</p>
        @endif
        <p class="status">Status atual: {{ $status['last_run']['status'] ?? 'idle' }}</p>
        <div class="actions">
            <form method="POST" action="{{ route('updater.check') }}">@csrf<button type="submit">Checar atualizações</button></form>
            <form method="POST" action="{{ route('updater.trigger.update') }}">@csrf<button type="submit">Disparar atualização</button></form>
            <form method="POST" action="{{ route('updater.trigger.rollback') }}">@csrf<button type="submit">Rollback último</button></form>
        </div>
    </div>

    <div class="card">
        <h3>Último run</h3>
        <pre>{{ json_encode($lastRun, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    <div class="card">
        <h3>Histórico</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Início</th><th>Fim</th><th>Status</th><th>Rev Before</th><th>Rev After</th>
                </tr>
            </thead>
            <tbody>
            @foreach($runs as $run)
                <tr>
                    <td>{{ $run['id'] }}</td>
                    <td>{{ $run['started_at'] }}</td>
                    <td>{{ $run['finished_at'] }}</td>
                    <td>{{ $run['status'] }}</td>
                    <td>{{ $run['revision_before'] }}</td>
                    <td>{{ $run['revision_after'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
