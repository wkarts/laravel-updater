<div class="form-grid">
    <div>
        <label for="name">Nome</label>
        <input id="name" name="name" value="{{ old('name', $profile['name'] ?? '') }}" required>
    </div>
    <div>
        <label for="retention_backups">Retenção de backups</label>
        <input id="retention_backups" type="number" min="1" max="200" name="retention_backups" value="{{ old('retention_backups', $profile['retention_backups'] ?? 10) }}">
    </div>
    @php($fields = ['backup_enabled' => 'Backup ativado', 'dry_run' => 'Modo dry-run', 'force' => 'Forçar execução', 'composer_install' => 'Rodar composer', 'migrate' => 'Rodar migrations', 'seed' => 'Rodar seed', 'rollback_on_fail' => 'Rollback em falha', 'active' => 'Definir como perfil ativo'])
    @foreach($fields as $field => $label)
        <label class="form-inline"><input type="checkbox" name="{{ $field }}" value="1" style="max-width:20px;" {{ old($field, (int) ($profile[$field] ?? 0)) == 1 ? 'checked' : '' }}><span>{{ $label }}</span></label>
    @endforeach
</div>
