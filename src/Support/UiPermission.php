<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Http\Request;

class UiPermission
{
    /** @return array<string,string> */
    public function definitions(): array
    {
        return [
            'dashboard.view' => 'Visualizar dashboard',
            'updates.view' => 'Visualizar atualizações',
            'updates.run' => 'Executar atualização',
            'updates.rollback' => 'Executar rollback',
            'maintenance.manage' => 'Gerenciar manutenção manual',
            'runs.view' => 'Visualizar execuções',
            'sources.manage' => 'Gerenciar fontes',
            'profiles.manage' => 'Gerenciar perfis',
            'backups.manage' => 'Gerenciar backups e restore',
            'logs.view' => 'Visualizar logs',
            'seeds.manage' => 'Gerenciar seeds',
            'users.manage' => 'Gerenciar usuários',
            'settings.manage' => 'Gerenciar configurações e tokens',
            'profile.manage' => 'Acessar/editar próprio perfil',
        ];
    }

    public function requiredPermissionForRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        $map = [
            'updater.index' => 'dashboard.view',
            'updater.status' => 'dashboard.view',
            'updater.check' => 'updates.view',
            'updater.section' => null,
            'updater.trigger.update' => 'updates.run',
            'updater.trigger.rollback' => 'updates.rollback',
            'updater.maintenance.on' => 'maintenance.manage',
            'updater.maintenance.off' => 'maintenance.manage',
            'updater.updates.' => 'updates.run',
            'updater.runs.' => 'runs.view',
            'updater.backups.' => 'backups.manage',
            'updater.sources.' => 'sources.manage',
            'updater.profiles.' => 'profiles.manage',
            'updater.users.' => 'users.manage',
            'updater.settings.' => 'settings.manage',
            'updater.seeds.' => 'seeds.manage',
            'updater.logs' => 'logs.view',
            'updater.profile' => 'profile.manage',
            'updater.profile.' => 'profile.manage',
            'updater.logout' => 'profile.manage',
        ];

        if (array_key_exists($routeName, $map)) {
            return $map[$routeName];
        }

        foreach ($map as $prefix => $permission) {
            if (str_ends_with($prefix, '.') && str_starts_with($routeName, $prefix)) {
                return $permission;
            }
        }

        return null;
    }

    public function canAccessSection(array $user, string $section): bool
    {
        $required = match ($section) {
            'updates' => 'updates.view',
            'runs' => 'runs.view',
            'sources' => 'sources.manage',
            'profiles' => 'profiles.manage',
            'backups' => 'backups.manage',
            'logs' => 'logs.view',
            'security' => 'settings.manage',
            'admin-users' => 'users.manage',
            'settings' => 'settings.manage',
            'seeds' => 'seeds.manage',
            default => null,
        };

        if ($required === null) {
            return true;
        }

        return $this->has($user, $required);
    }

    public function has(array $user, string $permission): bool
    {
        if ($this->isMaster($user)) {
            return true;
        }

        $permissions = $this->extractPermissions($user);
        if ($permissions === [] && ((bool) ($user['is_admin'] ?? false))) {
            return true; // fallback legado
        }

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function authorize(Request $request, string $permission): void
    {
        $user = (array) $request->attributes->get('updater_user', []);
        abort_if(!$this->has($user, $permission), 403, 'Sem permissão para esta ação.');
    }

    public function isMaster(array $user): bool
    {
        if ((bool) ($user['is_master'] ?? false)) {
            return true;
        }

        $masterEmail = mb_strtolower(trim((string) config('updater.ui.auth.master_email', '')));
        if ($masterEmail === '') {
            return false;
        }

        return mb_strtolower(trim((string) ($user['email'] ?? ''))) === $masterEmail;
    }

    /** @return array<int,string> */
    public function normalizePermissions(array $permissions): array
    {
        $allowed = array_keys($this->definitions());
        $clean = [];
        foreach ($permissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission === '' || !in_array($permission, $allowed, true)) {
                continue;
            }
            $clean[] = $permission;
        }

        return array_values(array_unique($clean));
    }

    /** @return array<int,string> */
    public function extractPermissions(array $user): array
    {
        $raw = $user['permissions'] ?? $user['permissions_json'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return $this->normalizePermissions(is_array($raw) ? $raw : []);
    }
}
