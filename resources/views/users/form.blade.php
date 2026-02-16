@php
    $selectedPermissions = old('permissions');
    if (!is_array($selectedPermissions)) {
        $decoded = json_decode((string) ($user['permissions_json'] ?? '[]'), true);
        $selectedPermissions = is_array($decoded) ? $decoded : [];
    }
    $master = trim((string) ($masterEmail ?? ''));
    $isMasterUser = $master !== '' && mb_strtolower(trim((string) ($user['email'] ?? ''))) === mb_strtolower($master);
@endphp

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

    <label class="form-inline form-inline-check"><input type="checkbox" name="is_admin" value="1" {{ old('is_admin', (int) ($user['is_admin'] ?? 0)) == 1 ? 'checked' : '' }}><span>Usuário administrador</span></label>
    <label class="form-inline form-inline-check"><input type="checkbox" name="is_active" value="1" {{ old('is_active', isset($user) ? (int) ($user['is_active'] ?? 0) : 1) == 1 ? 'checked' : '' }}><span>Usuário ativo</span></label>

    <div>
        <label>Permissões do perfil</label>
        @if($isMasterUser)
            <p class="muted">Usuário master definido no ENV possui acesso total e ignora permissões do perfil.</p>
        @endif

        <div class="permissions-grid">
            @foreach(($permissionDefinitions ?? []) as $permissionKey => $permissionLabel)
                <label class="permission-item">
                    <input type="checkbox" name="permissions[]" value="{{ $permissionKey }}" {{ in_array($permissionKey, $selectedPermissions, true) ? 'checked' : '' }} {{ $isMasterUser ? 'disabled' : '' }}>
                    <span>{{ $permissionLabel }}</span>
                </label>
            @endforeach
        </div>
    </div>
</div>
