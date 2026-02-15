<div class="card">
    <h3>Página de manutenção</h3>
    <p class="muted">
        Define qual view será usada no <code>php artisan down --render=...</code>.
        Você pode manter <code>errors::503</code> (do seu app) ou usar a página whitelabel do pacote:
        <code>laravel-updater::maintenance</code>.
    </p>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('laravel-updater.settings.maintenance.save') }}">
        @csrf

        <div class="grid-2">
            <div class="field">
                <label for="render_view">Render view</label>
                <input id="render_view" name="render_view" type="text" value="{{ old('render_view', $maintenance['render_view'] ?? '') }}" placeholder="errors::503 ou laravel-updater::maintenance">
                @error('render_view') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="title">Título</label>
                <input id="title" name="title" type="text" value="{{ old('title', $maintenance['title'] ?? '') }}" placeholder="Ex.: Fersoft ERP">
                @error('title') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="field">
            <label for="message">Mensagem</label>
            <textarea id="message" name="message" rows="3" placeholder="Mensagem exibida durante a manutenção">{{ old('message', $maintenance['message'] ?? '') }}</textarea>
            @error('message') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label for="footer">Rodapé</label>
            <input id="footer" name="footer" type="text" value="{{ old('footer', $maintenance['footer'] ?? '') }}" placeholder="Ex.: Atualização em andamento">
            @error('footer') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="actions">
            <button class="btn primary" type="submit">Salvar</button>
        </div>

        <div class="hint">
            <strong>Dica:</strong> se o seu servidor quebrar no <code>artisan down --render=errors::503</code> com erro de <code>REQUEST_URI</code>,
            o Updater faz fallback automático para <code>artisan down</code> simples (configurável).
        </div>
    </form>
</div>
