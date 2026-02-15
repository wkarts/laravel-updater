<div class="card">
    <h3>Branding (White-label)</h3>
    <form method="POST" action="{{ route('updater.settings.branding.save') }}" enctype="multipart/form-data" class="form-grid" style="margin-top: 12px;">
        @csrf
        <input name="app_name" value="{{ $branding['app_name'] ?? '' }}" placeholder="Nome da aplicação">
        <input name="app_sufix_name" value="{{ $branding['app_sufix_name'] ?? '' }}" placeholder="Sufixo do nome">
        <input name="app_desc" value="{{ $branding['app_desc'] ?? '' }}" placeholder="Descrição curta">
        <input name="primary_color" value="{{ $branding['primary_color'] ?? '#3b82f6' }}" placeholder="#3b82f6">
        <input name="maintenance_title" value="{{ $branding['maintenance_title'] ?? '' }}" placeholder="Título da manutenção (opcional)">
        <textarea name="maintenance_message" rows="3" placeholder="Mensagem de manutenção (opcional)">{{ $branding['maintenance_message'] ?? '' }}</textarea>
        <input name="maintenance_footer" value="{{ $branding['maintenance_footer'] ?? '' }}" placeholder="Rodapé da manutenção (opcional)">
        <input type="file" name="logo">
        <input type="file" name="favicon">
        <button class="btn btn-primary" type="submit">Salvar branding</button>
    </form>
    <div class="form-inline" style="margin-top: 10px;">
        <form method="POST" action="{{ route('updater.settings.branding.reset') }}">@csrf <button class="btn btn-secondary" type="submit">Resetar branding para ENV</button></form>
        <form method="POST" action="{{ route('updater.settings.branding.asset.remove', 'logo') }}" onsubmit="return confirm('Remover logo atual?')">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Remover logo</button></form>
        <form method="POST" action="{{ route('updater.settings.branding.asset.remove', 'favicon') }}" onsubmit="return confirm('Remover favicon atual?')">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Remover favicon</button></form>
    </div>
</div>