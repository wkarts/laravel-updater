@extends('laravel-updater::layout')
@section('page_title', 'Backups')

@section('content')
<div class="card">
    <h3>Ações de backup manual</h3>
    <div class="form-inline" style="gap:8px; flex-wrap:wrap;">
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'database']) }}" onsubmit="window.updaterBackupLoading()">@csrf <button class="btn btn-primary" type="submit">Gerar backup do Banco agora</button></form>
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'snapshot']) }}" onsubmit="window.updaterBackupLoading()">@csrf <button class="btn" type="submit">Gerar snapshot da aplicação completa</button></form>
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'full']) }}" onsubmit="window.updaterBackupLoading()">@csrf <button class="btn" type="submit">Gerar backup completo agora</button></form>
        <a class="btn" href="{{ route('updater.backups.log.download') }}">Baixar log do updater</a>
    </div>
</div>

<div class="card">
    <h3>Andamento em tempo real</h3>
    <p id="backup-progress-text" class="muted">Aguardando ações...</p>
    <div style="height:10px; background:#e5e7eb; border-radius:999px; overflow:hidden;">
        <div id="backup-progress-bar" style="height:10px; width:0%; background:#3b82f6; transition:width .3s;"></div>
    </div>
    <ul id="backup-progress-logs" style="margin-top:10px;"></ul>
</div>

<div class="card">
    <h3>Backups disponíveis</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Tipo</th><th>Arquivo</th><th>Run</th><th>Tamanho</th><th>Data</th><th>Ações</th></tr></thead>
            <tbody>
            @forelse($backups as $backup)
                <tr>
                    <td>#{{ $backup['id'] }}</td>
                    <td>{{ strtoupper($backup['type']) }}</td>
                    <td>{{ $backup['path'] }}</td>
                    <td>{{ $backup['run_id'] ?? '-' }}</td>
                    <td>{{ number_format((int) ($backup['size'] ?? 0), 0, ',', '.') }} bytes</td>
                    <td>{{ $backup['created_at'] }}</td>
                    <td>
                        <a class="btn" href="{{ route('updater.backups.download', ['id' => $backup['id']]) }}">Baixar backup</a>
                        <a class="btn btn-danger" href="{{ route('updater.backups.restore.form', ['id' => $backup['id']]) }}">Restaurar</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Nenhum backup registrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
window.updaterBackupLoading = function () {
    const txt = document.getElementById('backup-progress-text');
    const bar = document.getElementById('backup-progress-bar');
    if (txt) txt.innerText = 'Processando... aguarde.';
    if (bar) bar.style.width = '35%';
};

async function updaterBackupPoll() {
    try {
        const res = await fetch('{{ route('updater.backups.progress.status') }}', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        const data = await res.json();
        const runs = data.runs || [];
        const logs = data.logs || [];

        const txt = document.getElementById('backup-progress-text');
        const bar = document.getElementById('backup-progress-bar');
        if (runs.length > 0) {
            const running = runs.find(r => (r.status || '').toLowerCase() === 'running');
            if (running) {
                txt.innerText = 'Executando run #' + running.id + '...';
                bar.style.width = '65%';
            } else {
                txt.innerText = 'Última atualização: ' + (data.updated_at || '-');
                bar.style.width = '100%';
                setTimeout(() => bar.style.width = '0%', 1800);
            }
        }

        const ul = document.getElementById('backup-progress-logs');
        if (ul) {
            ul.innerHTML = '';
            logs.slice(0, 5).forEach(log => {
                const li = document.createElement('li');
                li.textContent = '[' + (log.level || 'info') + '] ' + (log.message || '');
                ul.appendChild(li);
            });
        }
    } catch (e) {
        // silencioso
    }
}

setInterval(updaterBackupPoll, 4000);
updaterBackupPoll();
</script>
@endsection
