@extends('laravel-updater::layout')
@section('page_title', 'Sources')

@section('content')
<div class="grid">
    <div class="card">
        <h3>Nova source</h3>
        <form method="POST" action="{{ route('updater.sources.save') }}" class="form-grid">
            @csrf
            <input name="name" placeholder="Nome" required>
            <select name="type"><option>github</option><option>gitlab</option><option>bitbucket</option><option>git</option><option>zip</option></select>
            <input name="repo_url" placeholder="URL do repositório" required>
            <input name="branch" placeholder="Branch" value="main">
            <select name="auth_mode"><option>none</option><option>token</option><option>ssh</option></select>
            <input name="token_encrypted" placeholder="Token (oculto na listagem)">
            <label class="form-inline" style="align-items:center;"><input type="checkbox" name="active" value="1" style="max-width:20px;"><span>Marcar como ativa</span></label>
            <button class="btn btn-primary" type="submit">Salvar source</button>
        </form>

        <form method="POST" action="{{ route('updater.sources.test') }}" style="margin-top:10px">
            @csrf
            <button class="btn btn-secondary" type="submit">Testar conexão (simulado)</button>
        </form>
    </div>

    <div class="card">
        <h3>Sources cadastradas</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Tipo</th><th>Branch</th><th>Ativa</th><th>Ação</th></tr></thead>
                <tbody>
                @forelse($sources as $source)
                    <tr>
                        <td>{{ $source['name'] }}</td>
                        <td>{{ strtoupper($source['type']) }}</td>
                        <td>{{ $source['branch'] ?: '-' }}</td>
                        <td>{{ (int) $source['active'] === 1 ? 'Sim' : 'Não' }}</td>
                        <td>
                            <form method="POST" action="{{ route('updater.sources.activate', $source['id']) }}">@csrf <button class="btn btn-secondary" type="submit">Ativar</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Nenhuma source cadastrada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
