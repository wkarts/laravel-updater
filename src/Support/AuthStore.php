<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PDO;

class AuthStore
{
    public function __construct(private readonly StateStore $stateStore)
    {
    }

    public function ensureSchema(): void
    {
        $this->stateStore->ensureSchema();
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

    public function createUser(string $email, string $password, bool $isAdmin = false): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO updater_users (email, password_hash, is_admin, is_active, created_at, updated_at) VALUES (:email, :password_hash, :is_admin, 1, :created_at, :updated_at)');
        $now = date(DATE_ATOM);
        $stmt->execute([
            ':email' => mb_strtolower(trim($email)),
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ':is_admin' => $isAdmin ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, (string) ($user['password_hash'] ?? ''));
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            ':updated_at' => date(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function updateTotp(int $userId, ?string $secret, bool $enabled): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_users SET totp_secret = :secret, totp_enabled = :enabled, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':secret' => $secret,
            ':enabled' => $enabled ? 1 : 0,
            ':updated_at' => date(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function createSession(int $userId, ?string $ip, ?string $userAgent, int $ttlMinutes): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $createdAt = new DateTimeImmutable();
        $expiresAt = $createdAt->modify('+' . $ttlMinutes . ' minutes');

        $stmt = $this->pdo()->prepare('INSERT INTO updater_sessions (id, user_id, ip, user_agent, created_at, expires_at) VALUES (:id, :user_id, :ip, :user_agent, :created_at, :expires_at)');
        $stmt->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':created_at' => $createdAt->format(DATE_ATOM),
            ':expires_at' => $expiresAt->format(DATE_ATOM),
        ]);

        $this->touchLastLogin($userId);

        return $sessionId;
    }

    public function findValidSession(string $sessionId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT s.*, u.email, u.is_admin, u.is_active, u.totp_enabled, u.totp_secret FROM updater_sessions s INNER JOIN updater_users u ON u.id = s.user_id WHERE s.id = :id LIMIT 1');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->invalidateSession($sessionId);
            return null;
        }

        return $row;
    }

    public function invalidateSession(string $sessionId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM updater_sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
    }

    public function isRateLimited(string $email, ?string $ip, int $maxAttempts, int $decayMinutes): bool
    {
        $row = $this->attemptRow($email, $ip);
        if (!$row) {
            return false;
        }

        $lastAttempt = strtotime((string) $row['last_attempt_at']);
        if ($lastAttempt === false || $lastAttempt < time() - ($decayMinutes * 60)) {
            return false;
        }

        return (int) $row['attempts'] >= $maxAttempts;
    }

    public function registerLoginFailure(string $email, ?string $ip, int $decayMinutes): void
    {
        $row = $this->attemptRow($email, $ip);
        $now = date(DATE_ATOM);

        if (!$row) {
            $stmt = $this->pdo()->prepare('INSERT INTO updater_login_attempts (email, ip, attempts, last_attempt_at) VALUES (:email, :ip, 1, :last_attempt_at)');
            $stmt->execute([
                ':email' => mb_strtolower(trim($email)),
                ':ip' => $ip,
                ':last_attempt_at' => $now,
            ]);
            return;
        }

        $attempts = (int) $row['attempts'];
        $lastAttempt = strtotime((string) $row['last_attempt_at']);
        if ($lastAttempt === false || $lastAttempt < time() - ($decayMinutes * 60)) {
            $attempts = 0;
        }

        $stmt = $this->pdo()->prepare('UPDATE updater_login_attempts SET attempts = :attempts, last_attempt_at = :last_attempt_at WHERE id = :id');
        $stmt->execute([
            ':attempts' => $attempts + 1,
            ':last_attempt_at' => $now,
            ':id' => $row['id'],
        ]);
    }

    public function clearLoginFailures(string $email, ?string $ip): void
    {
        if ($ip === null) {
            $stmt = $this->pdo()->prepare('DELETE FROM updater_login_attempts WHERE email = :email AND ip IS NULL');
            $stmt->execute([':email' => mb_strtolower(trim($email))]);
            return;
        }

        $stmt = $this->pdo()->prepare('DELETE FROM updater_login_attempts WHERE email = :email AND ip = :ip');
        $stmt->execute([
            ':email' => mb_strtolower(trim($email)),
            ':ip' => $ip,
        ]);
    }

    public function ensureDefaultAdmin(): void
    {
        if (!(bool) $this->cfg('updater.ui.auth.enabled', false) || !(bool) $this->cfg('updater.ui.auth.auto_provision_admin', true)) {
            return;
        }

        $email = (string) $this->cfg('updater.ui.auth.default_email', 'admin@admin.com');
        $password = (string) $this->cfg('updater.ui.auth.default_password', '123456');

        if ($this->findUserByEmail($email) !== null) {
            return;
        }

        $this->createUser($email, $password, true);
    }

    private function touchLastLogin(int $userId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE updater_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
        $now = date(DATE_ATOM);
        $stmt->execute([
            ':last_login_at' => $now,
            ':updated_at' => $now,
            ':id' => $userId,
        ]);
    }

    private function attemptRow(string $email, ?string $ip): ?array
    {
        if ($ip === null) {
            $stmt = $this->pdo()->prepare('SELECT * FROM updater_login_attempts WHERE email = :email AND ip IS NULL LIMIT 1');
            $stmt->execute([':email' => mb_strtolower(trim($email))]);
        } else {
            $stmt = $this->pdo()->prepare('SELECT * FROM updater_login_attempts WHERE email = :email AND ip = :ip LIMIT 1');
            $stmt->execute([
                ':email' => mb_strtolower(trim($email)),
                ':ip' => $ip,
            ]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function pdo(): PDO
    {
        return $this->stateStore->pdo();
    }

    private function cfg(string $key, mixed $default): mixed
    {
        if (function_exists('config')) {
            $container = Container::getInstance();
            if ($container instanceof Container && $container->bound('config')) {
                return config($key, $default);
            }
        }

        return $default;
    }
}

