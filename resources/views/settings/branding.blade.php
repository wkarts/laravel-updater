<div class="card">
    <h3>Branding (White-label)</h3>
    <p class="muted">Defina claramente os ativos visuais: <strong>logo do painel</strong>, <strong>favicon do painel</strong> e <strong>logo da manutenção (503)</strong>.</p>

    <form method="POST" action="{{ route('updater.settings.branding.save') }}" enctype="multipart/form-data" class="form-grid" style="margin-top: 12px;">
        @csrf

        <label for="app_name">Nome da aplicação (exibido no painel)</label>
        <input id="app_name" name="app_name" value="{{ $branding['app_name'] ?? '' }}" placeholder="Nome da aplicação">

        <label for="app_sufix_name">Sufixo do nome (painel)</label>
        <input id="app_sufix_name" name="app_sufix_name" value="{{ $branding['app_sufix_name'] ?? '' }}" placeholder="Sufixo do nome">

        <label for="app_desc">Descrição curta (painel)</label>
        <input id="app_desc" name="app_desc" value="{{ $branding['app_desc'] ?? '' }}" placeholder="Descrição curta">

        <label for="primary_color">Cor primária do painel</label>
        <input id="primary_color" name="primary_color" value="{{ $branding['primary_color'] ?? '#3b82f6' }}" placeholder="#3b82f6">

        <hr>

        <label for="maintenance_title">Título da manutenção (503)</label>
        <input id="maintenance_title" name="maintenance_title" value="{{ $branding['maintenance_title'] ?? '' }}" placeholder="Título da manutenção (opcional)">

        <label for="maintenance_message">Mensagem da manutenção (503)</label>
        <textarea id="maintenance_message" name="maintenance_message" rows="3" placeholder="Mensagem de manutenção (opcional)">{{ $branding['maintenance_message'] ?? '' }}</textarea>

        <label for="maintenance_footer">Rodapé da manutenção (503)</label>
        <input id="maintenance_footer" name="maintenance_footer" value="{{ $branding['maintenance_footer'] ?? '' }}" placeholder="Rodapé da manutenção (opcional)">

        <hr>

        <label for="logo">Logo do painel (menu/topbar)</label>
        <input id="logo" type="file" name="logo">

        <label for="favicon">Favicon do painel (aba do navegador)</label>
        <input id="favicon" type="file" name="favicon">

        <label for="maintenance_logo">Logo da manutenção (503)</label>
        <input id="maintenance_logo" type="file" name="maintenance_logo">
        <p class="muted">A manutenção usa a mesma técnica de priorização do branding: upload específico de manutenção &gt; URL de manutenção no config/env &gt; logo padrão do painel.</p>

        <hr>

        <div class="settings-runtime-grid">
            <label class="settings-toggle" for="first_run_assume_behind">
                <input id="first_run_assume_behind" type="checkbox" name="first_run_assume_behind" value="1" {{ (int) ($branding['first_run_assume_behind'] ?? 1) === 1 ? 'checked' : '' }}>
                <span>Na primeira execução sem .git, assumir atualização pendente</span>
            </label>

            <div class="settings-compact-field">
                <label for="first_run_assume_behind_commits">Commits assumidos na primeira execução</label>
                <input id="first_run_assume_behind_commits" type="number" min="1" max="9999" name="first_run_assume_behind_commits" value="{{ (int) ($branding['first_run_assume_behind_commits'] ?? 1) }}" placeholder="1">
            </div>

            <label class="settings-toggle" for="enter_maintenance_on_update_start">
                <input id="enter_maintenance_on_update_start" type="checkbox" name="enter_maintenance_on_update_start" value="1" {{ (int) ($branding['enter_maintenance_on_update_start'] ?? 1) === 1 ? 'checked' : '' }}>
                <span>Entrar em manutenção no início da atualização</span>
            </label>
        </div>

        <button class="btn btn-primary" type="submit">Salvar branding e comportamento</button>
    </form>

    <div class="form-inline" style="margin-top: 10px;">
        <form method="POST" action="{{ route('updater.settings.branding.reset') }}">@csrf <button class="btn btn-secondary" type="submit">Resetar para padrão</button></form>
        <form method="POST" action="{{ route('updater.settings.branding.asset.remove', 'logo') }}" onsubmit="return confirm('Remover logo do painel?')">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Remover logo painel</button></form>
        <form method="POST" action="{{ route('updater.settings.branding.asset.remove', 'favicon') }}" onsubmit="return confirm('Remover favicon do painel?')">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Remover favicon painel</button></form>
        <form method="POST" action="{{ route('updater.settings.branding.asset.remove', 'maintenance-logo') }}" onsubmit="return confirm('Remover logo da manutenção?')">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Remover logo manutenção</button></form>
    </div>
</div>
