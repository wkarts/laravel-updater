@extends('laravel-updater::layout')
@section('page_title', 'Backups')

@section('content')
<div class="card">
    <h3>Ações de backup manual</h3>
    <div class="form-inline" style="gap:8px; flex-wrap:wrap;">
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'database']) }}">@csrf <button class="btn btn-primary" type="submit">Gerar backup do Banco agora</button></form>
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'snapshot']) }}">@csrf <button class="btn" type="submit">Gerar snapshot do Código agora</button></form>
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'full']) }}">@csrf <button class="btn" type="submit">Gerar backup completo agora</button></form>
        <a class="btn" href="{{ route('updater.backups.log.download') }}">Baixar log do updater</a>
    </div>
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
@endsection
