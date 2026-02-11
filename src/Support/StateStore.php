<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use PDO;

class StateStore
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $sqlitePath)
    {
    }

    public function ensureSchema(): void
    {
        $this->connect()->exec('CREATE TABLE IF NOT EXISTS runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            status TEXT NOT NULL,
            revision_before TEXT NULL,
            revision_after TEXT NULL,
            backup_file TEXT NULL,
            snapshot_file TEXT NULL,
            options_json TEXT NULL,
            error_json TEXT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS patches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            sha256 TEXT NOT NULL UNIQUE,
            executed_at TEXT NOT NULL,
            run_id INTEGER NULL,
            revision TEXT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS artifacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            file_path TEXT NOT NULL,
            created_at TEXT NOT NULL,
            metadata_json TEXT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            totp_secret TEXT NULL,
            totp_enabled INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_login_at TEXT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_sessions (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            ip TEXT NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_attempt_at TEXT NOT NULL
        )');

        $this->connect()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_updater_login_attempts_email_ip ON updater_login_attempts(email, ip)');

        $this->ensureColumn('updater_users', 'last_login_at', 'TEXT NULL');
        $this->ensureColumn('updater_users', 'totp_secret', 'TEXT NULL');
        $this->ensureColumn('updater_users', 'totp_enabled', 'INTEGER NOT NULL DEFAULT 0');
    }

    public function createRun(array $options): int
    {
        $stmt = $this->connect()->prepare('INSERT INTO runs (started_at, status, options_json) VALUES (:started_at, :status, :options_json)');
        $stmt->execute([
            ':started_at' => date(DATE_ATOM),
            ':status' => 'running',
            ':options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->connect()->lastInsertId();
    }

    public function finishRun(int $runId, array $context, ?array $error = null): void
    {
        $stmt = $this->connect()->prepare('UPDATE runs SET finished_at=:finished_at, status=:status, revision_before=:revision_before, revision_after=:revision_after, backup_file=:backup_file, snapshot_file=:snapshot_file, error_json=:error_json WHERE id=:id');
        $stmt->execute([
            ':finished_at' => date(DATE_ATOM),
            ':status' => $error === null ? 'success' : 'failed',
            ':revision_before' => $context['revision_before'] ?? null,
            ':revision_after' => $context['revision_after'] ?? null,
            ':backup_file' => $context['backup_file'] ?? null,
            ':snapshot_file' => $context['snapshot_file'] ?? null,
            ':error_json' => $error ? json_encode($error, JSON_UNESCAPED_UNICODE) : null,
            ':id' => $runId,
        ]);
    }

    public function lastRun(): ?array
    {
        $stmt = $this->connect()->query('SELECT * FROM runs ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentRuns(int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->connect()->prepare('SELECT * FROM runs ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function patchAlreadyExecuted(string $sha256): bool
    {
        $stmt = $this->connect()->prepare('SELECT COUNT(*) FROM patches WHERE sha256 = :sha256');
        $stmt->execute([':sha256' => $sha256]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function registerPatch(string $filename, string $sha256, int $runId, ?string $revision): void
    {
        $stmt = $this->connect()->prepare('INSERT OR IGNORE INTO patches (filename, sha256, executed_at, run_id, revision) VALUES (:filename,:sha256,:executed_at,:run_id,:revision)');
        $stmt->execute([
            ':filename' => $filename,
            ':sha256' => $sha256,
            ':executed_at' => date(DATE_ATOM),
            ':run_id' => $runId,
            ':revision' => $revision,
        ]);
    }

    public function registerArtifact(string $type, string $path, array $metadata = []): void
    {
        $stmt = $this->connect()->prepare('INSERT INTO artifacts (type, file_path, created_at, metadata_json) VALUES (:type,:file_path,:created_at,:metadata_json)');
        $stmt->execute([
            ':type' => $type,
            ':file_path' => $path,
            ':created_at' => date(DATE_ATOM),
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function path(): string
    {
        return $this->sqlitePath;
    }

    public function pdo(): PDO
    {
        return $this->connect();
    }

    private function connect(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dir = dirname($this->sqlitePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return $this->pdo;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->connect()->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($columns as $tableColumn) {
            if (($tableColumn['name'] ?? '') === $column) {
                return;
            }
        }

        $this->connect()->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
