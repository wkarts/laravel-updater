@extends('laravel-updater::layout')
@section('title', 'Configurações')
@section('page_title', 'Configurações')
@section('breadcrumbs', 'Configurações')

@section('content')
<div class="settings-stack">
    <section class="settings-top-grid">
        <div class="settings-block settings-branding">
            @include('laravel-updater::settings.branding', ['branding' => $branding])
        </div>
    </section>

    <section class="settings-block">
        <div class="card">
            <h3>Fonte ativa e limpeza</h3>
            <p>Fonte ativa: <strong>{{ $activeSource['name'] ?? 'Nenhuma' }}</strong></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Nome</th><th>Tipo</th><th>Ativa</th><th>Ações</th></tr></thead>
                    <tbody>
                    @forelse($sources as $source)
                        <tr>
                            <td>{{ $source['name'] }}</td>
                            <td>{{ strtoupper($source['type']) }}</td>
                            <td>{{ (int) $source['active'] === 1 ? 'Sim' : 'Não' }}</td>
                            <td>
                                <div class="form-inline">
                                    <form method="POST" action="{{ route('updater.sources.activate', $source['id']) }}">@csrf <button class="btn btn-secondary" type="submit">Ativar</button></form>
                                    <form method="POST" action="{{ route('updater.sources.delete', $source['id']) }}" onsubmit="return confirm('Deseja remover esta fonte?')">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Excluir</button></form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Nenhuma fonte cadastrada.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        <div class="settings-side-stack">
            @include('laravel-updater::settings.security')
            @include('laravel-updater::settings.tokens', ['tokens' => $tokens])

            <section class="settings-block">
                <div class="card">
                    <h3>Fonte ativa e limpeza</h3>
                    <p>Fonte ativa: <strong>{{ $activeSource['name'] ?? 'Nenhuma' }}</strong></p>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nome</th><th>Tipo</th><th>Ativa</th><th>Ações</th></tr></thead>
                            <tbody>
                            @forelse($sources as $source)
                                <tr>
                                    <td>{{ $source['name'] }}</td>
                                    <td>{{ strtoupper($source['type']) }}</td>
                                    <td>{{ (int) $source['active'] === 1 ? 'Sim' : 'Não' }}</td>
                                    <td>
                                        <div class="form-inline">
                                            <form method="POST" action="{{ route('updater.sources.activate', $source['id']) }}">@csrf <button class="btn btn-secondary" type="submit">Ativar</button></form>
                                            <form method="POST" action="{{ route('updater.sources.delete', $source['id']) }}" onsubmit="return confirm('Deseja remover esta fonte?')">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Excluir</button></form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">Nenhuma fonte cadastrada.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

        <div class="settings-side-stack">
            @include('laravel-updater::settings.sources', ['activeSource' => $activeSource, 'sources' => $sources, 'profiles' => $profiles])
            @include('laravel-updater::settings.security')
            @include('laravel-updater::settings.tokens', ['tokens' => $tokens])
        </div>
    </section>
</div>
@endsection
