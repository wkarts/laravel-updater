<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class OperationsController extends Controller
{
    public function __construct(
        private readonly ManagerStore $managerStore,
        private readonly StateStore $stateStore,
        private readonly ShellRunner $shell,
        private readonly BackupDriverInterface $backupDriver,
        private readonly FileManager $fileManager,
        private readonly ArchiveManager $archiveManager
    ) {
    }

    public function executeUpdate(Request $request, TriggerDispatcher $dispatcher): RedirectResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'integer'],
            'source_id' => ['required', 'integer'],
            'update_mode' => ['required', 'in:merge,ff-only,tag,full-update'],
            'target_tag' => ['nullable', 'string', 'max:120'],
            'dry_run_before' => ['nullable', 'boolean'],
        ], [
            'profile_id.required' => 'Selecione um perfil.',
            'source_id.required' => 'Selecione uma fonte.',
            'update_mode.required' => 'Selecione o modo de atualização.',
        ]);

        $this->managerStore->activateProfile((int) $data['profile_id']);
        $this->managerStore->setActiveSource((int) $data['source_id']);

        $updateType = $this->mapUpdateType((string) $data['update_mode']);
        $targetTag = trim((string) ($data['target_tag'] ?? ''));

        if ($updateType === 'git_tag' && $targetTag === '') {
            return back()->withErrors(['target_tag' => 'Selecione uma tag para atualizar por tag.'])->withInput();
        }

        if ((bool) $request->boolean('dry_run_before', true)) {
            $runId = $dispatcher->triggerUpdate([
                'dry_run' => true,
                'allow_dirty' => false,
                'update_type' => $updateType,
                'target_tag' => $targetTag,
                'profile_id' => (int) $data['profile_id'],
                'source_id' => (int) $data['source_id'],
            ]);

            if ($runId === null) {
                return back()->with('status', 'Dry-run disparado. Aguarde e confira em Execuções para aprovar.');
            }

            session()->put('updater_pending_approval_' . $runId, [
                'profile_id' => (int) $data['profile_id'],
                'source_id' => (int) $data['source_id'],
                'update_type' => $updateType,
                'target_tag' => $targetTag,
            ]);

            return redirect()->route('updater.runs.show', ['id' => $runId])
                ->with('status', 'Dry-run concluído. Revise e aprove para executar a atualização real.');
        }

        $this->performMandatoryFullBackup($request);

        $runId = $dispatcher->triggerUpdate([
            'allow_dirty' => false,
            'dry_run' => false,
            'update_type' => $updateType,
            'target_tag' => $targetTag,
            'profile_id' => (int) $data['profile_id'],
            'source_id' => (int) $data['source_id'],
        ]);

        if ($runId !== null) {
            return redirect()->route('updater.runs.show', ['id' => $runId])
                ->with('status', 'Atualização iniciada com backup FULL obrigatório concluído.');
        }

        return back()->with('status', 'Atualização disparada com backup FULL obrigatório concluído.');
    }

    public function approveAndExecute(int $id, Request $request, TriggerDispatcher $dispatcher): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $actor = request()->attributes->get('updater_user');
        abort_if(!is_array($actor) || !(bool) ($actor['is_admin'] ?? false), 403);

        $user = $this->managerStore->findUser((int) $actor['id']);
        if ($user === null || !password_verify((string) $request->input('password'), (string) $user['password_hash'])) {
            return back()->withErrors(['password' => 'Senha de administrador inválida.']);
        }

        $pending = session()->get('updater_pending_approval_' . $id);
        if (!is_array($pending)) {
            return back()->withErrors(['approval' => 'Não há execução pendente de aprovação para este dry-run.']);
        }

        $this->performMandatoryFullBackup($request);

        $runId = $dispatcher->triggerUpdate([
            'allow_dirty' => false,
            'dry_run' => false,
            'update_type' => (string) ($pending['update_type'] ?? 'git_merge'),
            'target_tag' => (string) ($pending['target_tag'] ?? ''),
            'profile_id' => (int) ($pending['profile_id'] ?? 0),
            'source_id' => (int) ($pending['source_id'] ?? 0),
        ]);

        session()->forget('updater_pending_approval_' . $id);

        if ($runId !== null) {
            return redirect()->route('updater.runs.show', ['id' => $runId])
                ->with('status', 'Atualização aprovada e iniciada com backup FULL obrigatório.');
        }

        return redirect()->route('updater.section', ['section' => 'runs'])
            ->with('status', 'Atualização aprovada e disparada com backup FULL obrigatório.');
    }

    public function runDetails(int $id)
    {
        $run = $this->stateStore->findRun($id);
        abort_if($run === null, 404);

        return view('laravel-updater::runs.show', [
            'run' => $run,
            'logs' => $this->managerStore->logs($id),
            'pendingApproval' => session()->has('updater_pending_approval_' . $id),
        ]);
    }

    public function backupNow(string $type, Request $request): RedirectResponse
    {
        $runId = $this->stateStore->createRun(['manual_backup' => $type]);
        $this->stateStore->addRunLog($runId, 'info', 'Iniciando backup manual.', ['tipo' => $type]);

        try {
            $created = match ($type) {
                'database' => $this->createDatabaseBackup($runId),
                'snapshot' => $this->createSnapshotBackup($runId),
                'full' => $this->createFullBackup($runId),
                default => throw new \RuntimeException('Tipo de backup inválido.'),
            };

            $this->stateStore->finishRun($runId, ['revision_before' => null, 'revision_after' => null]);
            $this->stateStore->addRunLog($runId, 'info', 'Backup manual finalizado com sucesso.', [
                'tipo' => $type,
                'backup_id' => $created['id'],
                'arquivo' => $created['path'],
            ]);
            $this->managerStore->addAuditLog($this->actorId($request), 'backup', [
                'tipo' => $type,
                'run_id' => $runId,
                'backup_id' => $created['id'],
            ], $request->ip(), $request->userAgent());

            return back()->with('status', 'Backup manual gerado com sucesso.');
        } catch (\Throwable $e) {
            $this->stateStore->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);
            $this->stateStore->addRunLog($runId, 'error', 'Falha ao gerar backup manual.', [
                'tipo' => $type,
                'erro' => $e->getMessage(),
            ]);

            return back()->withErrors(['backup' => 'Falha ao gerar backup: ' . $e->getMessage()]);
        }
    }

    public function downloadBackup(int $id)
    {
        $backup = $this->findBackup($id);
        abort_if($backup === null, 404);

        $path = (string) ($backup['path'] ?? '');
        if (is_file($path)) {
            return response()->download($path, basename($path));
        }

        $disk = (string) config('updater.backup.upload_disk', '');
        $prefix = trim((string) config('updater.backup.upload_prefix', 'updater/backups'), '/');
        $remote = $prefix . '/' . basename($path);
        if ($disk !== '' && Storage::disk($disk)->exists($remote)) {
            return response()->streamDownload(function () use ($disk, $remote): void {
                $stream = Storage::disk($disk)->readStream($remote);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, basename($path));
        }

        abort(404);
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
                $this->archiveManager->extractArchive($path, base_path());
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

    public function progressStatus(): JsonResponse
    {
        $runningStmt = $this->stateStore->pdo()->prepare("SELECT * FROM runs WHERE options_json LIKE :q AND status = 'running' ORDER BY id DESC LIMIT 1");
        $runningStmt->execute([':q' => '%manual_backup%']);
        $runningRun = $runningStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $lastStmt = $this->stateStore->pdo()->prepare("SELECT * FROM runs WHERE options_json LIKE :q ORDER BY id DESC LIMIT 1");
        $lastStmt->execute([':q' => '%manual_backup%']);
        $lastRun = $lastStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $targetRunId = (int) ($runningRun['id'] ?? $lastRun['id'] ?? 0);
        $logs = [];
        if ($targetRunId > 0) {
            $logStmt = $this->stateStore->pdo()->prepare('SELECT * FROM updater_logs WHERE run_id = :run_id ORDER BY id DESC LIMIT 20');
            $logStmt->execute([':run_id' => $targetRunId]);
            $logs = $logStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $active = $runningRun !== null;
        $progress = 0;
        $message = 'Sem backup em execução no momento.';

        if ($active) {
            $progress = 65;
            $message = 'Executando run #' . (int) $runningRun['id'] . '...';
        } elseif ($lastRun !== null) {
            $status = strtolower((string) ($lastRun['status'] ?? ''));
            if ($status === 'success') {
                $progress = 100;
                $message = 'Último backup concluído com sucesso (run #' . (int) $lastRun['id'] . ').';
            } elseif ($status === 'failed') {
                $progress = 100;
                $message = 'Último backup falhou (run #' . (int) $lastRun['id'] . ').';
            }
        }

        return response()->json([
            'active' => $active,
            'progress' => $progress,
            'message' => $message,
            'run' => $runningRun ?? $lastRun,
            'logs' => $logs,
            'updated_at' => date(DATE_ATOM),
        ]);
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


    private function performMandatoryFullBackup(Request $request): void
    {
        $runId = $this->stateStore->createRun(['manual_backup' => 'full', 'triggered_via' => 'ui_update']);
        $this->stateStore->addRunLog($runId, 'info', 'Backup FULL obrigatório antes da atualização.');

        try {
            $full = $this->createFullBackup($runId);
            $this->stateStore->finishRun($runId, ['revision_before' => null, 'revision_after' => null, 'backup_file' => $full['path']]);
            $this->managerStore->addAuditLog($this->actorId($request), 'backup_full_before_update', [
                'run_id' => $runId,
                'backup_id' => $full['id'],
            ], $request->ip(), $request->userAgent());
        } catch (\Throwable $e) {
            $this->stateStore->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function mapUpdateType(string $mode): string
    {
        return match ($mode) {
            'merge' => 'git_merge',
            'ff-only' => 'git_ff_only',
            'tag' => 'git_tag',
            'full-update' => 'zip_release',
            default => 'git_merge',
        };
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

        $filePath = $snapshotPath . '/manual-snapshot-' . date('Ymd-His') . '.zip';
        $excludes = config('updater.paths.exclude_snapshot', []);
        $this->archiveManager->createZipFromDirectory(base_path(), $filePath, $excludes);

        return $this->insertBackupRow('snapshot', $filePath, $runId);
    }

    private function createFullBackup(int $runId): array
    {
        $db = $this->createDatabaseBackup($runId);
        $snapshot = $this->createSnapshotBackup($runId);

        $fullPath = rtrim((string) config('updater.backup.path'), '/') . '/manual-full-' . date('Ymd-His') . '.zip';
        $this->archiveManager->createZipFromFiles([
            (string) $db['path'] => 'database/' . basename((string) $db['path']),
            (string) $snapshot['path'] => 'snapshot/' . basename((string) $snapshot['path']),
        ], $fullPath);

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

        $disk = (string) config('updater.backup.upload_disk', '');
        $prefix = trim((string) config('updater.backup.upload_prefix', 'updater/backups'), '/');
        if ($disk !== '' && is_file($path)) {
            $remote = $prefix . '/' . basename($path);
            $stream = fopen($path, 'rb');
            if (is_resource($stream)) {
                Storage::disk($disk)->put($remote, $stream);
                fclose($stream);
            }
        }

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
