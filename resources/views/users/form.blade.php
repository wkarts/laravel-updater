<div class="form-grid">
    <div>
        <label for="name">Nome</label>
        <input id="name" name="name" value="{{ old('name', $user['name'] ?? '') }}" required>
    </div>
    <div>
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email', $user['email'] ?? '') }}" required>
    </div>
    <div>
        <label for="password">Senha {{ isset($user) ? '(deixe em branco para manter)' : '' }}</label>
        <input id="password" type="password" name="password" {{ isset($user) ? '' : 'required' }}>
    </div>
    <div>
        <label for="password_confirmation">Confirmar senha</label>
        <input id="password_confirmation" type="password" name="password_confirmation" {{ isset($user) ? '' : 'required' }}>
    </div>
    <label class="form-inline"><input type="checkbox" name="is_admin" value="1" style="max-width:20px;" {{ old('is_admin', (int) ($user['is_admin'] ?? 0)) == 1 ? 'checked' : '' }}><span>Usuário administrador</span></label>
    <label class="form-inline"><input type="checkbox" name="is_active" value="1" style="max-width:20px;" {{ old('is_active', isset($user) ? (int) ($user['is_active'] ?? 0) : 1) == 1 ? 'checked' : '' }}><span>Usuário ativo</span></label>
</div>
