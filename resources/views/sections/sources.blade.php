@extends('laravel-updater::layout')
@section('page_title', 'Fontes')

@section('content')
<div class="grid">
    <div class="card">
        <h3>{{ is_array($editingSource ?? null) ? 'Editar fonte' : 'Nova fonte' }}</h3>
        <p class="muted" style="margin-bottom:10px;">Você pode cadastrar várias fontes, mas apenas <strong>UMA</strong> deve ficar ativa por vez para evitar conflitos.</p>

        <form method="POST" action="{{ route('updater.sources.save') }}" class="form-grid">
            @csrf
            <input type="hidden" name="id" value="{{ $editingSource['id'] ?? '' }}">

            <div>
                <label for="name">Nome</label>
                <input id="name" name="name" required value="{{ old('name', $editingSource['name'] ?? '') }}">
            </div>

            <div>
                <label for="type">Tipo</label>
                <select id="type" name="type" required>
                    @php($currentType = old('type', $editingSource['type'] ?? 'git_merge'))
                    <option value="git_merge" @selected($currentType === 'git_merge')>Git Merge (recomendado)</option>
                    <option value="git_ff_only" @selected($currentType === 'git_ff_only')>Git FF-only</option>
                    <option value="git_tag" @selected($currentType === 'git_tag')>Git por Tag</option>
                    <option value="zip_release" @selected($currentType === 'zip_release')>ZIP Release</option>
                    <option value="github" @selected($currentType === 'github')>GitHub</option>
                    <option value="gitlab" @selected($currentType === 'gitlab')>GitLab</option>
                    <option value="bitbucket" @selected($currentType === 'bitbucket')>Bitbucket</option>
                </select>
            </div>

            <div>
                <label for="repo_url">URL do repositório</label>
                <input id="repo_url" name="repo_url" required value="{{ old('repo_url', $editingSource['repo_url'] ?? '') }}">
            </div>

            <div>
                <label for="branch">Branch</label>
                <input id="branch" name="branch" value="{{ old('branch', $editingSource['branch'] ?? 'main') }}">
            </div>

            <div>
                <label for="auth_mode">Autenticação</label>
                @php($authMode = old('auth_mode', $editingSource['auth_mode'] ?? 'none'))
                <select id="auth_mode" name="auth_mode" required>
                    <option value="none" @selected($authMode === 'none')>Sem autenticação</option>
                    <option value="token" @selected($authMode === 'token')>Token</option>
                    <option value="ssh" @selected($authMode === 'ssh')>SSH</option>
                </select>
            </div>

            <div>
                <label for="token_encrypted">Token (opcional)</label>
                <input id="token_encrypted" name="token_encrypted" value="{{ old('token_encrypted', $editingSource['token_encrypted'] ?? '') }}">
            </div>

            <div>
                <label for="ssh_private_key_path">Caminho da chave SSH (opcional)</label>
                <input id="ssh_private_key_path" name="ssh_private_key_path" value="{{ old('ssh_private_key_path', $editingSource['ssh_private_key_path'] ?? '') }}">
            </div>

            <label class="form-inline" style="align-items:center;">
                @php($activeValue = old('active', (int) ($editingSource['active'] ?? 0)) ? 1 : 0)
                <input type="checkbox" name="active" value="1" style="max-width:20px;" {{ $activeValue ? 'checked' : '' }}>
                <span>Definir como fonte ativa</span>
            </label>

            <div class="form-inline">
                <button class="btn btn-primary" type="submit">{{ is_array($editingSource ?? null) ? 'Atualizar fonte' : 'Salvar fonte' }}</button>
                @if(is_array($editingSource ?? null))
                    <a class="btn btn-secondary" href="{{ route('updater.section', ['section' => 'sources']) }}">Cancelar edição</a>
                @endif
            </div>
        </form>

        <form method="POST" action="{{ route('updater.sources.test') }}" style="margin-top:10px" class="form-grid">
            @csrf
            <input type="hidden" name="source_id" value="{{ $editingSource['id'] ?? '' }}">
            <button class="btn btn-secondary" type="submit">Testar conexão real da fonte</button>
        </form>
    </div>

    <div class="card">
        <h3>Fontes cadastradas</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Tipo</th><th>Branch</th><th>Ativa</th><th>Ações</th></tr></thead>
                <tbody>
                @forelse($sources as $source)
                    <tr>
                        <td>{{ $source['name'] }}</td>
                        <td>{{ strtoupper($source['type']) }}</td>
                        <td>{{ $source['branch'] ?: '-' }}</td>
                        <td>{{ (int) $source['active'] === 1 ? 'Sim' : 'Não' }}</td>
                        <td>
                            <div class="form-inline">
                                <form method="POST" action="{{ route('updater.sources.activate', $source['id']) }}">@csrf <button class="btn btn-secondary" type="submit">Ativar</button></form>
                                <a class="btn btn-secondary" href="{{ route('updater.section', ['section' => 'sources', 'edit' => $source['id']]) }}">Editar</a>
                                <form method="POST" action="{{ route('updater.sources.delete', $source['id']) }}" onsubmit="return confirm('Confirma excluir esta fonte?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn" type="submit">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Nenhuma fonte cadastrada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
