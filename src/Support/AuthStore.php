<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use PDO;

class AuthStore
{
    public function __construct(private readonly StateStore $stateStore)
    {
    }

    public function ensureAdminProvisioned(array $config): void
    {
        if (!(bool) ($config['auto_provision_admin'] ?? false)) {
            return;
        }

        $email = (string) ($config['default_email'] ?? 'admin@admin.com');
        if ($this->findUserByEmail($email) !== null) {
            return;
        }

        $this->createUser($email, (string) ($config['default_password'] ?? '123456'), true, true);
    }

    public function createUser(string $email, string $password, bool $admin = false, bool $active = true): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO updater_users (email, password_hash, is_admin, is_active, totp_secret, totp_enabled, created_at, updated_at) VALUES (:email,:password_hash,:is_admin,:is_active,NULL,0,:created_at,:updated_at)');
        $stmt->execute([
            ':email' => mb_strtolower(trim($email)),
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ':is_admin' => $admin ? 1 : 0,
            ':is_active' => $active ? 1 : 0,
            ':created_at' => date(DATE_ATOM),
            ':updated_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findUserById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function verifyPassword(array $user, string $password): bool
    {
        return (int) $user['is_active'] === 1 && password_verify($password, (string) $user['password_hash']);
    }

    public function updatePassword(int $userId, string $password): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_users SET password_hash=:password_hash, updated_at=:updated_at WHERE id=:id');
        $stmt->execute([
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ':updated_at' => date(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function updateTotp(int $userId, ?string $secret, bool $enabled): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_users SET totp_secret=:secret, totp_enabled=:enabled, updated_at=:updated_at WHERE id=:id');
        $stmt->execute([
            ':secret' => $secret,
            ':enabled' => $enabled ? 1 : 0,
            ':updated_at' => date(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function createSession(int $userId, string $ip, string $userAgent, int $ttlMinutes): string
    {
        $id = bin2hex(random_bytes(32));
        $stmt = $this->pdo()->prepare('INSERT INTO updater_sessions (id, user_id, created_at, expires_at, ip, user_agent) VALUES (:id,:user_id,:created_at,:expires_at,:ip,:user_agent)');
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':created_at' => date(DATE_ATOM),
            ':expires_at' => date(DATE_ATOM, time() + ($ttlMinutes * 60)),
            ':ip' => mb_substr($ip, 0, 45),
            ':user_agent' => mb_substr($userAgent, 0, 255),
        ]);

        return $id;
    }

    public function findValidSession(string $sessionId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_sessions WHERE id=:id AND expires_at >= :now LIMIT 1');
        $stmt->execute([':id' => $sessionId, ':now' => date(DATE_ATOM)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function deleteSession(string $sessionId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM updater_sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
    }

    public function tooManyAttempts(string $email, string $ip, int $maxAttempts = 5, int $cooldownSec = 900): bool
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM updater_login_attempts WHERE email=:email AND ip=:ip LIMIT 1');
        $stmt->execute([':email' => mb_strtolower(trim($email)), ':ip' => mb_substr($ip, 0, 45)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $last = strtotime((string) $row['last_attempt_at']) ?: 0;
        if (time() - $last > $cooldownSec) {
            return false;
        }

        return (int) $row['attempts'] >= $maxAttempts;
    }

    public function registerFailedAttempt(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $ip = mb_substr($ip, 0, 45);

        $stmt = $this->pdo()->prepare('SELECT * FROM updater_login_attempts WHERE email=:email AND ip=:ip LIMIT 1');
        $stmt->execute([':email' => $email, ':ip' => $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $insert = $this->pdo()->prepare('INSERT INTO updater_login_attempts (email, ip, attempts, last_attempt_at) VALUES (:email,:ip,1,:last_attempt_at)');
            $insert->execute([':email' => $email, ':ip' => $ip, ':last_attempt_at' => date(DATE_ATOM)]);
            return;
        }

        $update = $this->pdo()->prepare('UPDATE updater_login_attempts SET attempts=:attempts, last_attempt_at=:last_attempt_at WHERE email=:email AND ip=:ip');
        $update->execute([
            ':attempts' => ((int) $row['attempts']) + 1,
            ':last_attempt_at' => date(DATE_ATOM),
            ':email' => $email,
            ':ip' => $ip,
        ]);
    }

    public function clearAttempts(string $email, string $ip): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM updater_login_attempts WHERE email=:email AND ip=:ip');
        $stmt->execute([':email' => mb_strtolower(trim($email)), ':ip' => mb_substr($ip, 0, 45)]);
    }

    private function pdo(): PDO
    {
        return $this->stateStore->pdo();
    }
}
