@extends('laravel-updater::layout')
@section('page_title', 'Backups')

@section('content')
<div class="card">
    <h3>Ações de backup manual</h3>
    <div class="form-inline" style="gap:8px; flex-wrap:wrap;">
        <form class="backup-create-form" method="POST" action="{{ route('updater.backups.create', ['type' => 'database']) }}" onsubmit="window.updaterBackupLoading()">@csrf <button class="btn btn-primary" type="submit">Gerar backup do Banco agora</button></form>
        <form class="backup-create-form" method="POST" action="{{ route('updater.backups.create', ['type' => 'snapshot']) }}" onsubmit="window.updaterBackupLoading()">@csrf <button class="btn" type="submit">Gerar snapshot da aplicação completa</button></form>
        <form class="backup-create-form" method="POST" action="{{ route('updater.backups.create', ['type' => 'full']) }}" onsubmit="window.updaterBackupLoading()">@csrf <button class="btn" type="submit">Gerar backup completo agora</button></form>
        <a class="btn" href="{{ route('updater.backups.log.download') }}">Baixar log do updater</a>
    </div>
</div>

<div class="card">
    <h3>Andamento em tempo real</h3>
    <p id="backup-progress-text" class="muted">Aguardando ações...</p>
    <div style="height:10px; background:#e5e7eb; border-radius:999px; overflow:hidden;">
        <div id="backup-progress-bar" style="height:10px; width:0%; background:#3b82f6; transition:width .3s;"></div>
    </div>
    <div style="margin-top:10px;" id="backup-cancel-wrap" hidden>
        <form method="POST" action="{{ route('updater.backups.cancel') }}">@csrf <button class="btn btn-danger" type="submit">Cancelar backup em andamento</button></form>
    </div>
    <ul id="backup-progress-logs" style="margin-top:10px;"></ul>
</div>

<div class="card">
    <h3>Backups disponíveis</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Tipo</th><th>Arquivo</th><th>Run</th><th>Status nuvem</th><th>Tamanho</th><th>Data</th><th>Ações</th></tr></thead>
            <tbody>
            @forelse($backups as $backup)
                @php($cloudUploaded = (int) ($backup['cloud_uploaded'] ?? 0) === 1)
                <tr style="{{ $cloudUploaded ? 'background:#ecfdf3;' : '' }}">
                    <td>#{{ $backup['id'] }}</td>
                    <td>{{ strtoupper($backup['type']) }}</td>
                    <td>{{ $backup['path'] }}</td>
                    <td>{{ $backup['run_id'] ?? '-' }}</td>
                    <td>
                        @if($cloudUploaded)
                            <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#16a34a;color:#fff;font-size:12px;">Enviado para nuvem</span>
                            <div class="muted" style="margin-top:4px;font-size:12px;">{{ $backup['cloud_provider'] ?? 'nuvem' }} • {{ $backup['cloud_uploaded_at'] ?? '' }}</div>
                        @else
                            <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#94a3b8;color:#fff;font-size:12px;">Não enviado</span>
                        @endif
                        @if(!empty($backup['cloud_last_error']))
                            <div style="margin-top:4px;color:#b91c1c;font-size:12px;">Último erro: {{ $backup['cloud_last_error'] }}</div>
                        @endif
                        @if((int) ($backup['cloud_upload_count'] ?? 0) > 0)
                            <div class="muted" style="margin-top:4px;font-size:12px;">Envios realizados: {{ (int) $backup['cloud_upload_count'] }}</div>
                        @endif
                    </td>
                    <td>{{ number_format((int) ($backup['size'] ?? 0), 0, ',', '.') }} bytes</td>
                    <td>{{ $backup['created_at'] }}</td>
                    <td>
                        <a class="btn" href="{{ route('updater.backups.download', ['id' => $backup['id']]) }}">Baixar backup</a>
                        <form method="POST" action="{{ route('updater.backups.upload', ['id' => $backup['id']]) }}" style="display:inline-block;">@csrf <button class="btn btn-secondary" type="submit">Enviar para nuvem {{ $cloudUploaded ? '(reenviar)' : '' }}</button></form>
                        <a class="btn btn-danger" href="{{ route('updater.backups.restore.form', ['id' => $backup['id']]) }}">Restaurar</a>
                        <form method="POST" action="{{ route('updater.backups.delete', ['id' => $backup['id']]) }}" style="display:inline-block;" onsubmit="return confirm('Remover backup local e registro?');">@csrf @method('DELETE') <button class="btn" type="submit">Excluir</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Nenhum backup registrado.</td></tr>
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
        const logs = data.logs || [];

        const txt = document.getElementById('backup-progress-text');
        const bar = document.getElementById('backup-progress-bar');
        if (txt) txt.innerText = data.message || 'Sem backup em execução no momento.';
        if (bar) {
            const progress = Number(data.progress || 0);
            bar.style.width = Math.max(0, Math.min(100, progress)) + '%';
        }

        const cancelWrap = document.getElementById('backup-cancel-wrap');
        if (cancelWrap) {
            cancelWrap.hidden = !Boolean(data.can_cancel);
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


const createForms = document.querySelectorAll('form.backup-create-form');
createForms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        window.updaterBackupLoading();

        try {
            const formData = new FormData(form);
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': formData.get('_token') || '',
                },
                body: formData,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Falha ao iniciar backup.');
            }

            updaterBackupPoll();
        } catch (e) {
            const txt = document.getElementById('backup-progress-text');
            if (txt) txt.innerText = 'Falha ao iniciar backup: ' + (e.message || 'erro desconhecido');
        }
    });
});

setInterval(updaterBackupPoll, 4000);
updaterBackupPoll();
</script>
@endsection
