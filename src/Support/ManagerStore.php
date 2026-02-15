<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use PDO;

class ManagerStore
{
    public function __construct(private readonly StateStore $stateStore)
    {
    }

    public function resolvedBranding(): array
    {
        $row = $this->branding();

        $envName = (string) config('updater.app.name', (string) config('app.name', 'Laravel'));
        $envSuffix = (string) config('updater.app.sufix_name', '');
        $envDesc = (string) config('updater.app.desc', '');
        $envLogoUrl = (string) config('updater.app.logo_url', '');
        $envFaviconUrl = (string) config('updater.app.favicon_url', '');
        $envPrimary = (string) config('updater.app.primary_color', '#3b82f6');

        return [
            'app_name' => (string) ($row['app_name'] ?? $envName),
            'app_sufix_name' => (string) ($row['app_sufix_name'] ?? $envSuffix),
            'app_desc' => (string) ($row['app_desc'] ?? $envDesc),
            'app_name_full' => trim(((string) ($row['app_name'] ?? $envName)).' '.((string) ($row['app_sufix_name'] ?? $envSuffix))),
            'logo_path' => $row['logo_path'] ?? null,
            'favicon_path' => $row['favicon_path'] ?? null,
            'logo_url' => (string) ($row['logo_url'] ?? $envLogoUrl),
            'favicon_url' => (string) ($row['favicon_url'] ?? $envFaviconUrl),
            'primary_color' => (string) ($row['primary_color'] ?? $envPrimary),
            'maintenance_title' => (string) ($row['maintenance_title'] ?? config('updater.maintenance.default_title', 'Atualização em andamento')),
            'maintenance_message' => (string) ($row['maintenance_message'] ?? config('updater.maintenance.default_message', 'Estamos atualizando o sistema. Volte em alguns minutos.')),
            'maintenance_footer' => (string) ($row['maintenance_footer'] ?? config('updater.maintenance.default_footer', 'Obrigado pela compreensão.')),

            'is_custom' => $row !== null,
        ];
    }

    public function branding(): ?array
    {
        $stmt = $this->pdo()->query('SELECT * FROM updater_branding WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveBranding(array $data): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO updater_branding (id, app_name, app_sufix_name, app_desc, logo_path, favicon_path, primary_color, maintenance_title, maintenance_message, maintenance_footer, updated_at)
            VALUES (1, :app_name, :app_sufix_name, :app_desc, :logo_path, :favicon_path, :primary_color, :maintenance_title, :maintenance_message, :maintenance_footer, :updated_at)
            ON CONFLICT(id) DO UPDATE SET
            app_name = excluded.app_name,
            app_sufix_name = excluded.app_sufix_name,
            app_desc = excluded.app_desc,
            logo_path = excluded.logo_path,
            favicon_path = excluded.favicon_path,
            primary_color = excluded.primary_color,
            updated_at = excluded.updated_at');

        $stmt->execute([
            ':app_name' => $data['app_name'] ?? null,
            ':app_sufix_name' => $data['app_sufix_name'] ?? null,
            ':app_desc' => $data['app_desc'] ?? null,
            ':logo_path' => $data['logo_path'] ?? null,
            ':favicon_path' => $data['favicon_path'] ?? null,
            ':primary_color' => $data['primary_color'] ?? '#3b82f6',
            ':maintenance_title' => $data['maintenance_title'] ?? null,
            ':maintenance_message' => $data['maintenance_message'] ?? null,
            ':maintenance_footer' => $data['maintenance_footer'] ?? null,
            ':updated_at' => date(DATE_ATOM),
        ]);
    }

    public function resetBrandingToEnv(): void
    {
        $this->pdo()->exec('DELETE FROM updater_branding WHERE id = 1');
    }

    /** @return array<int,array<string,mixed>> */
    public function users(): array
    {
        $stmt = $this->pdo()->query('SELECT id,email,name,is_admin,is_active,totp_enabled,last_login_at FROM updater_users ORDER BY id DESC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findUser(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createUser(array $data): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO updater_users (name, email, password_hash, is_admin, is_active, totp_enabled, created_at, updated_at) VALUES (:name, :email, :password_hash, :is_admin, :is_active, 0, :created_at, :updated_at)');
        $now = date(DATE_ATOM);
        $stmt->execute([
            ':name' => $data['name'],
            ':email' => mb_strtolower(trim((string) $data['email'])),
            ':password_hash' => password_hash((string) $data['password'], PASSWORD_BCRYPT),
            ':is_admin' => (int) ($data['is_admin'] ?? 0),
            ':is_active' => (int) ($data['is_active'] ?? 1),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function updateUser(int $id, array $data): void
    {
        $fields = [
            'name = :name',
            'email = :email',
            'is_admin = :is_admin',
            'is_active = :is_active',
            'updated_at = :updated_at',
        ];

        $payload = [
            ':id' => $id,
            ':name' => $data['name'],
            ':email' => mb_strtolower(trim((string) $data['email'])),
            ':is_admin' => (int) ($data['is_admin'] ?? 0),
            ':is_active' => (int) ($data['is_active'] ?? 1),
            ':updated_at' => date(DATE_ATOM),
        ];

        if (!empty($data['password'])) {
            $fields[] = 'password_hash = :password_hash';
            $payload[':password_hash'] = password_hash((string) $data['password'], PASSWORD_BCRYPT);
        }

        $stmt = $this->pdo()->prepare('UPDATE updater_users SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($payload);
    }

    public function deleteUser(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM updater_users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $stmt = $this->pdo()->prepare('DELETE FROM updater_sessions WHERE user_id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function resetUserTwoFactor(int $id): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_users SET totp_secret = NULL, totp_enabled = 0, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':updated_at' => date(DATE_ATOM),
        ]);
    }

    public function activeAdminCount(): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM updater_users WHERE is_admin = 1 AND is_active = 1');

        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> */
    public function sources(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM updater_sources ORDER BY active DESC, id DESC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findSource(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_sources WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createOrUpdateSource(array $data, ?int $id = null): void
    {
        $payload = [
            ':name' => $data['name'],
            ':type' => $data['type'],
            ':repo_url' => $data['repo_url'],
            ':branch' => $data['branch'] ?? 'main',
            ':auth_mode' => $data['auth_mode'] ?? 'none',
            ':auth_username' => $data['auth_username'] ?? null,
            ':auth_password' => $data['auth_password'] ?? null,
            ':token_encrypted' => $data['token_encrypted'] ?? null,
            ':ssh_private_key_path' => $data['ssh_private_key_path'] ?? null,
            ':active' => (int) ($data['active'] ?? 0),
            ':updated_at' => date(DATE_ATOM),
        ];

        if ((int) ($data['active'] ?? 0) === 1) {
            $this->pdo()->exec('UPDATE updater_sources SET active = 0');
        }

        if ($id === null) {
            $stmt = $this->pdo()->prepare('INSERT INTO updater_sources (name, type, repo_url, branch, auth_mode, auth_username, auth_password, token_encrypted, ssh_private_key_path, active, created_at, updated_at)
            VALUES (:name, :type, :repo_url, :branch, :auth_mode, :auth_username, :auth_password, :token_encrypted, :ssh_private_key_path, :active, :created_at, :updated_at)');
            $payload[':created_at'] = date(DATE_ATOM);
        } else {
            $stmt = $this->pdo()->prepare('UPDATE updater_sources SET name=:name, type=:type, repo_url=:repo_url, branch=:branch, auth_mode=:auth_mode, auth_username=:auth_username, auth_password=:auth_password, token_encrypted=:token_encrypted, ssh_private_key_path=:ssh_private_key_path, active=:active, updated_at=:updated_at WHERE id=:id');
            $payload[':id'] = $id;
        }

        $stmt->execute($payload);
    }

    public function setActiveSource(int $id): void
    {
        $this->pdo()->exec('UPDATE updater_sources SET active = 0');
        $stmt = $this->pdo()->prepare('UPDATE updater_sources SET active = 1, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':updated_at' => date(DATE_ATOM), ':id' => $id]);
    }

    public function deleteSource(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM updater_sources WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activeSource(): ?array
    {
        $stmt = $this->pdo()->query('SELECT * FROM updater_sources WHERE active = 1 ORDER BY id DESC LIMIT 1');

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function profiles(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM updater_profiles ORDER BY active DESC, id DESC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findProfile(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createOrUpdateProfile(array $data, ?int $id = null): void
    {
        $fields = ['backup_enabled', 'dry_run', 'force', 'composer_install', 'migrate', 'seed', 'build_assets', 'health_check', 'rollback_on_fail', 'active'];
        foreach ($fields as $field) {
            $data[$field] = (int) ($data[$field] ?? 0);
        }
        if ($data['active'] === 1) {
            $this->pdo()->exec('UPDATE updater_profiles SET active = 0');
        }

        if ($id === null) {
            $stmt = $this->pdo()->prepare('INSERT INTO updater_profiles (name, backup_enabled, dry_run, force, composer_install, migrate, seed, build_assets, health_check, rollback_on_fail, retention_backups, active, post_update_commands)
            VALUES (:name,:backup_enabled,:dry_run,:force,:composer_install,:migrate,:seed,:build_assets,:health_check,:rollback_on_fail,:retention_backups,:active,:post_update_commands)');
        } else {
            $stmt = $this->pdo()->prepare('UPDATE updater_profiles SET name=:name, backup_enabled=:backup_enabled, dry_run=:dry_run, force=:force, composer_install=:composer_install, migrate=:migrate, seed=:seed, build_assets=:build_assets, health_check=:health_check, rollback_on_fail=:rollback_on_fail, retention_backups=:retention_backups, active=:active, post_update_commands=:post_update_commands WHERE id=:id');
        }

        $payload = [
            ':name' => $data['name'],
            ':backup_enabled' => $data['backup_enabled'],
            ':dry_run' => $data['dry_run'],
            ':force' => $data['force'],
            ':composer_install' => $data['composer_install'],
            ':migrate' => $data['migrate'],
            ':seed' => $data['seed'],
            ':build_assets' => $data['build_assets'],
            ':health_check' => $data['health_check'],
            ':rollback_on_fail' => $data['rollback_on_fail'],
            ':retention_backups' => (int) ($data['retention_backups'] ?? 10),
            ':active' => $data['active'],
            ':post_update_commands' => $data['post_update_commands'] ?? null,
        ];
        if ($id !== null) {
            $payload[':id'] = $id;
        }
        $stmt->execute($payload);
    }

    public function activateProfile(int $id): void
    {
        $this->pdo()->exec('UPDATE updater_profiles SET active = 0');
        $stmt = $this->pdo()->prepare('UPDATE updater_profiles SET active = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function deleteProfile(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM updater_profiles WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activeProfile(): ?array
    {
        $stmt = $this->pdo()->query('SELECT * FROM updater_profiles WHERE active = 1 ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function backups(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM updater_backups ORDER BY id DESC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function logs(?int $runId = null, ?string $level = null, ?string $q = null): array
    {
        $sql = 'SELECT * FROM updater_logs WHERE 1=1';
        $params = [];
        if ($runId !== null) {
            $sql .= ' AND run_id = :run_id';
            $params[':run_id'] = $runId;
        }
        if ($level !== null && $level !== '') {
            $sql .= ' AND level = :level';
            $params[':level'] = $level;
        }
        if ($q !== null && $q !== '') {
            $sql .= ' AND message LIKE :q';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            return $rows;
        }

        return $this->readFallbackLogs($level, $q);
    }

    public function generateApiToken(string $name): array
    {
        $plain = bin2hex(random_bytes(24));
        $stmt = $this->pdo()->prepare('INSERT INTO updater_api_tokens (name, token_hash, created_at, revoked_at) VALUES (:name, :token_hash, :created_at, NULL)');
        $stmt->execute([
            ':name' => $name,
            ':token_hash' => password_hash($plain, PASSWORD_BCRYPT),
            ':created_at' => date(DATE_ATOM),
        ]);

        return [
            'id' => (int) $this->pdo()->lastInsertId(),
            'token' => $plain,
        ];
    }

    public function revokeApiToken(int $id): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_api_tokens SET revoked_at = :revoked_at WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':revoked_at' => date(DATE_ATOM),
        ]);
    }

    public function apiTokens(): array
    {
        $stmt = $this->pdo()->query('SELECT id,name,created_at,revoked_at FROM updater_api_tokens ORDER BY id DESC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function validateApiToken(string $token): bool
    {
        $envToken = (string) config('updater.sync_token', '');
        if ($envToken !== '' && hash_equals($envToken, $token)) {
            return true;
        }

        $stmt = $this->pdo()->query('SELECT token_hash FROM updater_api_tokens WHERE revoked_at IS NULL');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (password_verify($token, (string) $row['token_hash'])) {
                return true;
            }
        }

        return false;
    }

    public function addAuditLog(?int $userId, string $action, array $meta = [], ?string $ip = null, ?string $userAgent = null): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO updater_audit_logs (user_id, action, meta_json, ip, user_agent, created_at) VALUES (:user_id, :action, :meta_json, :ip, :user_agent, :created_at)');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':created_at' => date(DATE_ATOM),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function readFallbackLogs(?string $level = null, ?string $q = null): array
    {
        $path = (string) config('updater.log.path', storage_path('logs/updater.log'));
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }

        $output = [];
        foreach (array_reverse($lines) as $line) {
            if (count($output) >= 200) {
                break;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $logLevel = strtolower((string) ($decoded['level_name'] ?? 'info'));
            $message = (string) ($decoded['message'] ?? '');

            if ($level !== null && $level !== '' && $logLevel !== strtolower($level) && !str_contains($logLevel, strtolower($level))) {
                continue;
            }

            if ($q !== null && $q !== '' && !str_contains(strtolower($message), strtolower($q))) {
                continue;
            }

            $output[] = [
                'created_at' => (string) ($decoded['datetime'] ?? date(DATE_ATOM)),
                'level' => $logLevel,
                'message' => $message,
                'run_id' => null,
            ];
        }

        return $output;
    }

    private function pdo(): PDO
    {
        return $this->stateStore->pdo();
    }
}