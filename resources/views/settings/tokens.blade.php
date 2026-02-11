<div class="card">
    <h3>Tokens de API</h3>
    @if(session('token_plain'))
        <div class="card" style="margin-bottom: 10px; background: var(--surface-soft);">
            <strong>Token gerado (copie agora):</strong>
            <pre>{{ session('token_plain') }}</pre>
        </div>
    @endif
    <form method="POST" action="{{ route('updater.settings.tokens.create') }}" class="form-inline" style="margin-bottom: 10px;">
        @csrf
        <input name="name" placeholder="Nome do token" required>
        <button class="btn btn-primary" type="submit">Criar token</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>Criado em</th><th>Status</th><th>Ação</th></tr></thead>
            <tbody>
            @forelse($tokens as $token)
                <tr>
                    <td>{{ $token['name'] }}</td>
                    <td>{{ $token['created_at'] }}</td>
                    <td>{{ empty($token['revoked_at']) ? 'Ativo' : 'Revogado' }}</td>
                    <td>
                        @if(empty($token['revoked_at']))
                            <form method="POST" action="{{ route('updater.settings.tokens.revoke', $token['id']) }}" onsubmit="return confirm('Revogar este token?')">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Revogar</button></form>
                        @else
                            <span class="muted">-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nenhum token criado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
