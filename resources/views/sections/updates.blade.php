@extends('laravel-updater::layout')
@section('page_title', 'Atualizações')

@section('content')
<div class="card">
    <h3>Status de atualização</h3>
    <p><strong>Revisão atual:</strong> {{ $statusCheck['current_revision'] ?? '-' }}</p>
    <p><strong>Revisão remota:</strong> {{ $statusCheck['remote'] ?? '-' }}</p>
    <p><strong>Commits pendentes:</strong> {{ (int) ($statusCheck['behind_by_commits'] ?? 0) }}</p>
    <p><strong>Última tag remota:</strong> {{ $statusCheck['latest_tag'] ?? '-' }}</p>
    <p><strong>Update disponível:</strong>
        @if((bool) ($statusCheck['has_updates'] ?? false) || (bool) ($statusCheck['has_update_by_tag'] ?? false))
            <span style="color:#16a34a;font-weight:700;">SIM</span>
        @else
            <span style="color:#64748b;font-weight:700;">NÃO</span>
        @endif
    </p>
    @if(!empty($statusCheck['warning']))
        <p class="muted">{{ $statusCheck['warning'] }}</p>
    @endif
</div>

<div class="card" style="margin-top:14px;">
    <h3>Executar atualização</h3>
    <p class="muted" style="margin-bottom:10px;">Você pode cadastrar várias fontes, mas apenas <strong>UMA</strong> deve ficar ativa por vez para evitar conflitos.</p>

    <form method="POST" action="{{ route('updater.updates.execute') }}" class="form-grid" style="margin-top:10px;">
        @csrf

        <div>
            <label for="profile_id">Perfil ativo</label>
            <select id="profile_id" name="profile_id" required>
                @foreach($profiles as $profile)
                    <option value="{{ $profile['id'] }}" @selected((int) old('profile_id', $activeProfile['id'] ?? 0) === (int) $profile['id'])>
                        {{ $profile['name'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="source_id">Fonte ativa</label>
            <select id="source_id" name="source_id" required>
                @foreach($sources as $source)
                    <option value="{{ $source['id'] }}" @selected((int) old('source_id', $activeSource['id'] ?? 0) === (int) $source['id'])>
                        {{ $source['name'] }} ({{ $source['branch'] ?? 'main' }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="update_mode">Modo de atualização</label>
            <select id="update_mode" name="update_mode" required onchange="document.getElementById('tag-wrapper').style.display = this.value === 'tag' ? 'block' : 'none';">
                <option value="merge" @selected(old('update_mode', 'merge') === 'merge')>merge (prioridade)</option>
                <option value="ff-only" @selected(old('update_mode') === 'ff-only')>ff-only</option>
                <option value="tag" @selected(old('update_mode') === 'tag')>tag</option>
                @if($fullUpdateEnabled)
                    <option value="full-update" @selected(old('update_mode') === 'full-update')>full update</option>
                @endif
            </select>
        </div>

        <div id="tag-wrapper" style="display:{{ old('update_mode') === 'tag' ? 'block' : 'none' }};">
            <label for="target_tag">Tag alvo</label>
            <select id="target_tag" name="target_tag">
                <option value="">Selecione uma tag</option>
                @foreach($availableTags as $tag)
                    <option value="{{ $tag }}" @selected(old('target_tag') === $tag)>{{ $tag }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label>
                <input type="checkbox" name="dry_run_before" value="1" {{ old('dry_run_before', '1') ? 'checked' : '' }}>
                Executar dry-run antes (recomendado)
            </label>
        </div>

        <div class="muted">Backup FULL é obrigatório e sempre será executado antes da atualização real.</div>

        <div class="form-inline">
            <button class="btn" data-update-action="1" type="submit" name="action" value="simulate">Simular (Dry-run)</button>
            <button class="btn btn-primary" data-update-action="1" type="submit" name="action" value="apply">Aplicar atualização</button>
        </div>
    </form>
</div>
@endsection

<div class="card" id="update-progress-card" style="margin-top:14px;">
    <h3>Progresso da atualização/rollback</h3>
    <div class="progress-track"><div class="progress-fill" id="update-progress-fill" style="width:0%"></div></div>
    <p id="update-progress-message" class="muted">Aguardando execução.</p>
    <ul id="update-progress-logs" class="muted" style="margin:0; padding-left:18px;"></ul>
</div>
