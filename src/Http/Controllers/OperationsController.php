<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OperationsController extends Controller
{
    public function __construct(
        private readonly ManagerStore $managerStore,
        private readonly StateStore $stateStore,
        private readonly ShellRunner $shell,
        private readonly BackupDriverInterface $backupDriver,
        private readonly FileManager $fileManager
    ) {
    }

    public function triggerDryRun(TriggerDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->triggerUpdate(['dry_run' => true]);

        return back()->with('status', 'Simulação (dry-run) disparada com sucesso.');
    }

    public function runDetails(int $id)
    {
        $run = $this->stateStore->findRun($id);
        abort_if($run === null, 404);

        return view('laravel-updater::runs.show', [
            'run' => $run,
            'logs' => $this->managerStore->logs($id),
        ]);
    }

    public function backupNow(string $type, Request $request): RedirectResponse
    {
        $runId = $this->stateStore->createRun(['manual_backup' => $type]);

        try {
            $created = match ($type) {
                'database' => $this->createDatabaseBackup($runId),
                'snapshot' => $this->createSnapshotBackup($runId),
                'full' => $this->createFullBackup($runId),
                default => throw new \RuntimeException('Tipo de backup inválido.'),
            };

            $this->stateStore->finishRun($runId, ['revision_before' => null, 'revision_after' => null]);
            $this->managerStore->addAuditLog($this->actorId($request), 'backup', [
                'tipo' => $type,
                'run_id' => $runId,
                'backup_id' => $created['id'],
            ], $request->ip(), $request->userAgent());

            return back()->with('status', 'Backup manual gerado com sucesso.');
        } catch (\Throwable $e) {
            $this->stateStore->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);

            return back()->withErrors(['backup' => 'Falha ao gerar backup: ' . $e->getMessage()]);
        }
    }

    public function downloadBackup(int $id)
    {
        $backup = $this->findBackup($id);
        abort_if($backup === null || !is_file((string) $backup['path']), 404);

        return response()->download((string) $backup['path'], basename((string) $backup['path']));
    }

    public function downloadUpdaterLog()
    {
        $logPath = (string) config('updater.log.path', storage_path('logs/updater.log'));
        abort_if(!is_file($logPath), 404);

        return response()->download($logPath, basename($logPath));
    }

    public function showRestoreForm(int $id)
    {
        $backup = $this->findBackup($id);
        abort_if($backup === null, 404);

        return view('laravel-updater::backups.restore', ['backup' => $backup]);
    }

    public function restoreBackup(int $id, Request $request): RedirectResponse
    {
        $request->validate([
            'confirmacao' => ['required', 'in:RESTAURAR'],
            'password' => ['required', 'string'],
        ]);

        $actor = request()->attributes->get('updater_user');
        abort_if(!is_array($actor) || !(bool) ($actor['is_admin'] ?? false), 403);

        $user = $this->managerStore->findUser((int) $actor['id']);
        if ($user === null || !password_verify((string) $request->input('password'), (string) $user['password_hash'])) {
            return back()->withErrors(['password' => 'Senha de administrador inválida.']);
        }

        $backup = $this->findBackup($id);
        abort_if($backup === null, 404);

        $path = (string) ($backup['path'] ?? '');
        if (!is_file($path) || !is_readable($path)) {
            return back()->withErrors(['restore' => 'Arquivo de backup inexistente ou sem permissão de leitura.']);
        }

        try {
            $type = (string) ($backup['type'] ?? '');
            if ($type === 'database') {
                $this->backupDriver->restore($path);
            } else {
                $this->shell->runOrFail(['tar', '-xzf', $path, '-C', base_path()]);
            }

            $this->managerStore->addAuditLog((int) $actor['id'], 'restore', [
                'backup_id' => $id,
                'tipo' => $backup['type'] ?? null,
                'path' => $path,
            ], $request->ip(), $request->userAgent());

            $this->stateStore->addRunLog(null, 'warning', 'Restore executado via UI.', [
                'backup_id' => $id,
                'tipo' => $backup['type'] ?? null,
            ]);

            return redirect()->route('updater.section', ['section' => 'backups'])
                ->with('status', 'Restore concluído com sucesso.');
        } catch (\Throwable $e) {
            $this->stateStore->addRunLog(null, 'error', 'Falha no restore via UI.', [
                'backup_id' => $id,
                'erro' => $e->getMessage(),
            ]);

            return back()->withErrors(['restore' => 'Falha no restore: ' . $e->getMessage()]);
        }
    }

    public function seedsIndex()
    {
        return view('laravel-updater::sections.seeds', ['seeds' => $this->stateStore->listSeedRegistry()]);
    }

    public function reapplySeed(Request $request): RedirectResponse
    {
        $actor = request()->attributes->get('updater_user');
        abort_if(!is_array($actor) || !(bool) ($actor['is_admin'] ?? false), 403);

        $request->validate(['seeder_class' => ['required', 'string']]);
        $seederClass = (string) $request->input('seeder_class');

        $this->shell->runOrFail(['php', 'artisan', 'db:seed', '--class=' . $seederClass, '--force']);
        $this->stateStore->registerSeed($seederClass, hash('sha256', $seederClass), null, 'Reaplicado manualmente');

        return back()->with('status', 'Seeder reaplicado com sucesso.');
    }

    private function createDatabaseBackup(int $runId): array
    {
        $name = 'manual-db-' . date('Ymd-His');
        $filePath = $this->backupDriver->backup($name);

        return $this->insertBackupRow('database', $filePath, $runId);
    }

    private function createSnapshotBackup(int $runId): array
    {
        $snapshotPath = rtrim((string) config('updater.snapshot.path'), '/');
        $this->fileManager->ensureDirectory($snapshotPath);

        $filePath = $snapshotPath . '/manual-snapshot-' . date('Ymd-His') . '.tar.gz';
        $excludes = config('updater.paths.exclude_snapshot', []);
        $excludeArgs = array_map(static fn (string $item): string => '--exclude=' . escapeshellarg($item), $excludes);
        $command = sprintf('tar -czf %s %s .', escapeshellarg($filePath), implode(' ', $excludeArgs));
        $this->shell->runOrFail(['bash', '-lc', $command]);

        return $this->insertBackupRow('snapshot', $filePath, $runId);
    }

    private function createFullBackup(int $runId): array
    {
        $db = $this->createDatabaseBackup($runId);
        $snapshot = $this->createSnapshotBackup($runId);

        $fullPath = rtrim((string) config('updater.backup.path'), '/') . '/manual-full-' . date('Ymd-His') . '.tar.gz';
        $command = sprintf(
            'tar -czf %s -C %s %s -C %s %s',
            escapeshellarg($fullPath),
            escapeshellarg(dirname((string) $db['path'])),
            escapeshellarg(basename((string) $db['path'])),
            escapeshellarg(dirname((string) $snapshot['path'])),
            escapeshellarg(basename((string) $snapshot['path']))
        );
        $this->shell->runOrFail(['bash', '-lc', $command]);

        return $this->insertBackupRow('full', $fullPath, $runId);
    }

    private function insertBackupRow(string $type, string $path, int $runId): array
    {
        $stmt = $this->stateStore->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, run_id) VALUES (:type,:path,:size,:created_at,:run_id)');
        $stmt->execute([
            ':type' => $type,
            ':path' => $path,
            ':size' => is_file($path) ? (int) filesize($path) : 0,
            ':created_at' => date(DATE_ATOM),
            ':run_id' => $runId,
        ]);

        return [
            'id' => (int) $this->stateStore->pdo()->lastInsertId(),
            'type' => $type,
            'path' => $path,
        ];
    }

    private function findBackup(int $id): ?array
    {
        $stmt = $this->stateStore->pdo()->prepare('SELECT * FROM updater_backups WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->attributes->get('updater_user');

        return is_array($user) ? (int) ($user['id'] ?? 0) : null;
    }
}
