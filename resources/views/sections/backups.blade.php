@extends('laravel-updater::layout')
@section('page_title', 'Backups')

@section('content')
<div class="card">
    <h3>Backups disponíveis</h3>
    <p class="muted">Em Windows, quando restore completo não for possível, utilize restore parcial com driver compatível.</p>

    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Tipo</th><th>Path</th><th>Tamanho</th><th>Data</th></tr></thead>
            <tbody>
            @forelse($backups as $backup)
                <tr>
                    <td>#{{ $backup['id'] }}</td>
                    <td>{{ strtoupper($backup['type']) }}</td>
                    <td>{{ $backup['path'] }}</td>
                    <td>{{ $backup['size'] }}</td>
                    <td>{{ $backup['created_at'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhum backup registrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
