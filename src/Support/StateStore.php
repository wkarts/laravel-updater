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
            name TEXT NULL,
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

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            action TEXT NOT NULL,
            meta_json TEXT NULL,
            ip TEXT NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_branding (
            id INTEGER PRIMARY KEY,
            app_name TEXT NULL,
            app_sufix_name TEXT NULL,
            app_desc TEXT NULL,
            logo_path TEXT NULL,
            favicon_path TEXT NULL,
            primary_color TEXT NULL,
            maintenance_title TEXT NULL,
            maintenance_message TEXT NULL,
            maintenance_footer TEXT NULL,
            updated_at TEXT NOT NULL
        )');

        // Backward-compatible upgrades: add new columns if the table already exists.
        foreach (['maintenance_title', 'maintenance_message', 'maintenance_footer'] as $col) {
            try {
                $this->connect()->exec('ALTER TABLE updater_branding ADD COLUMN ' . $col . ' TEXT NULL');
            } catch (\Throwable $e) {
                // ignore
            }
        }


        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            repo_url TEXT NOT NULL,
            branch TEXT NULL,
            auth_mode TEXT NOT NULL DEFAULT "none",
            auth_username TEXT NULL,
            auth_password TEXT NULL,
            token_encrypted TEXT NULL,
            ssh_private_key_path TEXT NULL,
            active INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            backup_enabled INTEGER NOT NULL DEFAULT 1,
            dry_run INTEGER NOT NULL DEFAULT 0,
            force INTEGER NOT NULL DEFAULT 0,
            composer_install INTEGER NOT NULL DEFAULT 1,
            migrate INTEGER NOT NULL DEFAULT 1,
            seed INTEGER NOT NULL DEFAULT 0,
            build_assets INTEGER NOT NULL DEFAULT 0,
            health_check INTEGER NOT NULL DEFAULT 1,
            rollback_on_fail INTEGER NOT NULL DEFAULT 0,
            retention_backups INTEGER NOT NULL DEFAULT 10,
            active INTEGER NOT NULL DEFAULT 0,
            post_update_commands TEXT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            path TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            profile_id INTEGER NULL,
            run_id INTEGER NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NULL,
            level TEXT NOT NULL,
            message TEXT NOT NULL,
            context_json TEXT NULL,
            created_at TEXT NOT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            revoked_at TEXT NULL
        )');

        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_recovery_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            used_at TEXT NULL
        )');


        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id INTEGER NOT NULL,
            profile_id INTEGER NOT NULL,
            last_notified_key TEXT NOT NULL,
            notified_at TEXT NOT NULL
        )');
        $this->connect()->exec('CREATE TABLE IF NOT EXISTS updater_seed_registry (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seeder_class TEXT NOT NULL,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL,
            app_revision TEXT NULL,
            notes TEXT NULL
        )');

        $this->connect()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_updater_login_attempts_email_ip ON updater_login_attempts(email, ip)');

        $this->ensureColumn('updater_users', 'name', 'TEXT NULL');
        $this->ensureColumn('updater_users', 'last_login_at', 'TEXT NULL');
        $this->ensureColumn('updater_users', 'totp_secret', 'TEXT NULL');
        $this->ensureColumn('updater_users', 'totp_enabled', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('updater_sources', 'auth_username', 'TEXT NULL');
        $this->ensureColumn('updater_sources', 'auth_password', 'TEXT NULL');
        $this->ensureColumn('updater_profiles', 'post_update_commands', 'TEXT NULL');
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

    public function updateRunStatus(int $runId, string $status, ?array $error = null): void
    {
        $stmt = $this->connect()->prepare('UPDATE runs SET finished_at=:finished_at, status=:status, error_json=:error_json WHERE id=:id');
        $stmt->execute([
            ':finished_at' => date(DATE_ATOM),
            ':status' => $status,
            ':error_json' => $error ? json_encode($error, JSON_UNESCAPED_UNICODE) : null,
            ':id' => $runId,
        ]);
    }

    public function findRun(int $runId): ?array
    {
        $stmt = $this->connect()->prepare('SELECT * FROM runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function addRunLog(?int $runId, string $level, string $message, array $context = []): void
    {
        $stmt = $this->connect()->prepare('INSERT INTO updater_logs (run_id, level, message, context_json, created_at) VALUES (:run_id, :level, :message, :context_json, :created_at)');
        $stmt->execute([
            ':run_id' => $runId,
            ':level' => $level,
            ':message' => $message,
            ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ':created_at' => date(DATE_ATOM),
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


    public function hasSeedApplied(string $seederClass, string $checksum): bool
    {
        $stmt = $this->connect()->prepare('SELECT COUNT(*) FROM updater_seed_registry WHERE seeder_class = :seeder_class AND checksum = :checksum');
        $stmt->execute([':seeder_class' => $seederClass, ':checksum' => $checksum]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function registerSeed(string $seederClass, string $checksum, ?string $appRevision = null, ?string $notes = null): void
    {
        $stmt = $this->connect()->prepare('INSERT INTO updater_seed_registry (seeder_class, checksum, applied_at, app_revision, notes) VALUES (:seeder_class, :checksum, :applied_at, :app_revision, :notes)');
        $stmt->execute([
            ':seeder_class' => $seederClass,
            ':checksum' => $checksum,
            ':applied_at' => date(DATE_ATOM),
            ':app_revision' => $appRevision,
            ':notes' => $notes,
        ]);
    }

    public function listSeedRegistry(): array
    {
        $stmt = $this->connect()->query('SELECT * FROM updater_seed_registry ORDER BY id DESC');

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


    public function lastNotification(int $sourceId, int $profileId): ?array
    {
        $stmt = $this->connect()->prepare('SELECT * FROM updater_notifications WHERE source_id = :source_id AND profile_id = :profile_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':source_id' => $sourceId, ':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveNotification(int $sourceId, int $profileId, string $key): void
    {
        $stmt = $this->connect()->prepare('INSERT INTO updater_notifications (source_id, profile_id, last_notified_key, notified_at) VALUES (:source_id, :profile_id, :last_notified_key, :notified_at)');
        $stmt->execute([
            ':source_id' => $sourceId,
            ':profile_id' => $profileId,
            ':last_notified_key' => $key,
            ':notified_at' => date(DATE_ATOM),
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

    public function connect(): PDO
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
