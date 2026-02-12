@extends('laravel-updater::layout')
@section('page_title', 'Backups')

@section('content')
<div class="card">
    <h3>Ações de backup manual</h3>
    <div class="form-inline" style="gap:8px;">
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'database']) }}">@csrf <button class="btn btn-primary" type="submit">Gerar backup do Banco agora</button></form>
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'snapshot']) }}">@csrf <button class="btn" type="submit">Gerar snapshot do Código agora</button></form>
        <form method="POST" action="{{ route('updater.backups.create', ['type' => 'full']) }}">@csrf <button class="btn" type="submit">Gerar backup completo agora</button></form>
    </div>
</div>

<div class="card">
    <h3>Backups disponíveis</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Tipo</th><th>Origem</th><th>Run</th><th>Tamanho</th><th>Data</th><th>Ações</th></tr></thead>
            <tbody>
            @forelse($backups as $backup)
                <tr>
                    <td>#{{ $backup['id'] }}</td>
                    <td>{{ strtoupper($backup['type']) }}</td>
                    <td>{{ $backup['path'] }}</td>
                    <td>{{ $backup['run_id'] ?? '-' }}</td>
                    <td>{{ $backup['size'] }}</td>
                    <td>{{ $backup['created_at'] }}</td>
                    <td>
                        <a class="btn" href="{{ route('updater.backups.download', ['id' => $backup['id']]) }}">Baixar</a>
                        <form method="POST" action="{{ route('updater.backups.restore', ['id' => $backup['id']]) }}" style="display:inline-block; margin-top:6px;">
                            @csrf
                            <input name="confirmacao" placeholder="RESTAURAR" required style="max-width:120px;">
                            <input type="password" name="password" placeholder="Senha admin" required style="max-width:140px;">
                            <button class="btn btn-danger" type="submit">Restaurar</button>
                        </form>
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
