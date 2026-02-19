<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Contracts\BackupDriverInterface;
use Argws\LaravelUpdater\Support\ArchiveManager;
use Argws\LaravelUpdater\Support\BackupCloudUploader;
use Argws\LaravelUpdater\Support\FileManager;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

class OperationsController extends Controller
{
    public function __construct(
        private readonly ManagerStore $managerStore,
        private readonly StateStore $stateStore,
        private readonly ShellRunner $shell,
        private readonly BackupDriverInterface $backupDriver,
        private readonly FileManager $fileManager,
        private readonly ArchiveManager $archiveManager,
        private readonly BackupCloudUploader $cloudUploader
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

        $action = (string) $request->input('action', 'apply');
        $shouldDryRunFirst = $action === 'simulate' || ($action === '' && (bool) $request->boolean('dry_run_before', true));

        $profile = $this->managerStore->activeProfile();
        $profileOptions = $this->resolveActiveBackupOptions();

        if ($shouldDryRunFirst) {

            try {
                $runId = $dispatcher->triggerUpdate([
                    'dry_run' => true,
                    'allow_dirty' => false,
                    'update_type' => $updateType,
                    'target_tag' => $targetTag,
                    'profile_id' => (int) $data['profile_id'],
                    'source_id' => (int) $data['source_id'],
                    'sync' => false,
                    'allow_http' => true,
                    'rollback_on_fail' => (bool) ($profile['rollback_on_fail'] ?? true),
                    'snapshot_include_vendor' => (bool) ($profileOptions['include_vendor'] ?? false),
                    'snapshot_compression' => (string) ($profileOptions['compression'] ?? 'zip'),
                ]);
            } catch (\Throwable $e) {
                return back()->withErrors(['update' => 'Falha ao executar dry-run: ' . $e->getMessage()])->withInput();
            }

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

        if ($this->requiresFullBackupBeforeUpdate()) {
            $this->performMandatoryFullBackup($request);
        }

        try {
            $runId = $dispatcher->triggerUpdate([
                'allow_dirty' => false,
                'dry_run' => false,
                'update_type' => $updateType,
                'target_tag' => $targetTag,
                'profile_id' => (int) $data['profile_id'],
                'source_id' => (int) $data['source_id'],
                'sync' => false,
                'allow_http' => true,
                'rollback_on_fail' => (bool) ($profile['rollback_on_fail'] ?? true),
                'snapshot_include_vendor' => (bool) ($profileOptions['include_vendor'] ?? false),
                'snapshot_compression' => (string) ($profileOptions['compression'] ?? 'zip'),
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => 'Falha ao aplicar atualização: ' . $e->getMessage()])->withInput();
        }

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
        abort_if(!is_array($actor), 403);

        $user = $this->managerStore->findUser((int) $actor['id']);
        if ($user === null || !password_verify((string) $request->input('password'), (string) $user['password_hash'])) {
            return back()->withErrors(['password' => 'Senha do usuário inválida.']);
        }

        $pending = session()->get('updater_pending_approval_' . $id);
        if (!is_array($pending)) {
            return back()->withErrors(['approval' => 'Não há execução pendente de aprovação para este dry-run.']);
        }

        if ($this->requiresFullBackupBeforeUpdate()) {
            $this->performMandatoryFullBackup($request);
        }

        try {
            $runId = $dispatcher->triggerUpdate([
                'allow_dirty' => false,
                'dry_run' => false,
                'update_type' => (string) ($pending['update_type'] ?? 'git_merge'),
                'target_tag' => (string) ($pending['target_tag'] ?? ''),
                'profile_id' => (int) ($pending['profile_id'] ?? 0),
                'source_id' => (int) ($pending['source_id'] ?? 0),
                'sync' => false,
                    'allow_http' => true,
                    'rollback_on_fail' => (bool) ($profile['rollback_on_fail'] ?? true),
                    'snapshot_include_vendor' => (bool) ($profileOptions['include_vendor'] ?? false),
                    'snapshot_compression' => (string) ($profileOptions['compression'] ?? 'zip'),
                ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => 'Falha ao executar atualização aprovada: ' . $e->getMessage()]);
        }

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

    public function backupNow(string $type, Request $request, TriggerDispatcher $dispatcher): RedirectResponse
    {
        if (!in_array($type, ['database', 'snapshot', 'full'], true)) {
            return back()->withErrors(['backup' => 'Tipo de backup inválido.']);
        }

        $runId = $this->stateStore->createRun(['manual_backup' => $type, 'triggered_via' => 'ui']);
        $this->stateStore->addRunLog($runId, 'info', 'Backup manual enfileirado.', ['tipo' => $type]);
        $backupOptions = $this->resolveActiveBackupOptions();

        try {
            $result = $dispatcher->triggerManualBackup($type, $runId);
            $this->managerStore->setRuntimeOption('backup_active_job', [
                'run_id' => $runId,
                'type' => $type,
                'pid' => $result['pid'] ?? null,
                'status' => 'running',
                'started_at' => date(DATE_ATOM),
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'system:update:backup') || str_contains(strtolower($e->getMessage()), 'namespace')) {
                return $this->runManualBackupInline($type, $runId, $request, $e->getMessage(), $backupOptions);
            }

            $this->stateStore->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);
            return back()->withErrors(['backup' => 'Falha ao iniciar backup: ' . $e->getMessage()]);
        }

        $this->managerStore->addAuditLog($this->actorId($request), 'backup_start', [
            'tipo' => $type,
            'run_id' => $runId,
        ], $request->ip(), $request->userAgent());

        return back()->with('status', 'Backup iniciado em segundo plano. Você pode continuar usando o painel.');
    }


    private function runManualBackupInline(string $type, int $runId, Request $request, string $reason, array $options): RedirectResponse
    {
        $this->stateStore->addRunLog($runId, 'warning', 'Fallback para backup síncrono ativado.', [
            'motivo' => $reason,
            'tipo' => $type,
        ]);

        try {
            $created = match ($type) {
                'database' => $this->createDatabaseBackup($runId, $options),
                'snapshot' => $this->createSnapshotBackup($runId, (string) ($options['compression'] ?? 'zip'), (bool) ($options['include_vendor'] ?? false)),
                'full' => $this->createFullBackup($runId, $options),
                default => throw new RuntimeException('Tipo de backup inválido.'),
            };

            $this->stateStore->finishRun($runId, ['revision_before' => null, 'revision_after' => null]);
            $this->managerStore->setRuntimeOption('backup_active_job', [
                'run_id' => $runId,
                'type' => $type,
                'pid' => null,
                'status' => 'finished',
                'finished_at' => date(DATE_ATOM),
            ]);
            $this->managerStore->addAuditLog($this->actorId($request), 'backup_sync_fallback', [
                'tipo' => $type,
                'run_id' => $runId,
                'backup_id' => $created['id'],
                'reason' => $reason,
            ], $request->ip(), $request->userAgent());

            return back()->with('status', 'Backup concluído em modo de compatibilidade (síncrono).');
        } catch (\Throwable $e) {
            $this->stateStore->updateRunStatus($runId, 'failed', ['message' => $e->getMessage()]);

            return back()->withErrors(['backup' => 'Falha no backup em modo de compatibilidade: ' . $e->getMessage()]);
        }

        $this->managerStore->addAuditLog($this->actorId($request), 'backup_start', [
            'tipo' => $type,
            'run_id' => $runId,
        ], $request->ip(), $request->userAgent());

        return back()->with('status', 'Backup iniciado em segundo plano. Você pode continuar usando o painel.');
    }

    public function downloadBackup(int $id)
    {
        $backup = $this->findBackup($id);
        abort_if($backup === null, 404);

        $path = (string) ($backup['path'] ?? '');
        if (is_file($path)) {
            return response()->download($path, basename($path));
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
        abort_if(!is_array($actor), 403);

        $user = $this->managerStore->findUser((int) $actor['id']);
        if ($user === null || !password_verify((string) $request->input('password'), (string) $user['password_hash'])) {
            return back()->withErrors(['password' => 'Senha do usuário inválida.']);
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


    public function updateProgressStatus(): JsonResponse
    {
        $runningStmt = $this->stateStore->pdo()->query("SELECT * FROM runs WHERE status = 'running' ORDER BY id DESC LIMIT 1");
        $runningRun = $runningStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $lastRun = $this->stateStore->lastRun();
        $targetRunId = (int) ($runningRun['id'] ?? $lastRun['id'] ?? 0);

        $logs = [];
        if ($targetRunId > 0) {
            $logStmt = $this->stateStore->pdo()->prepare('SELECT * FROM updater_logs WHERE run_id = :run_id ORDER BY id DESC LIMIT 30');
            $logStmt->execute([':run_id' => $targetRunId]);
            $logs = array_reverse($logStmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        }

        $progress = 0;
        $message = 'Aguardando execução.';

        if ($runningRun !== null) {
            $progress = min(95, 20 + (count($logs) * 7));
            $message = 'Atualização em andamento (run #' . (int) $runningRun['id'] . ').';
        } elseif (is_array($lastRun)) {
            $status = (string) ($lastRun['status'] ?? '');
            if ($status === 'success' || $status === 'DRY_RUN') {
                $progress = 100;
                $message = $status === 'DRY_RUN' ? 'Dry-run concluído.' : 'Atualização concluída com sucesso.';
            } elseif ($status === 'failed') {
                $progress = 100;
                $message = 'Atualização falhou. Verifique os detalhes.';
            }
        }

        return response()->json([
            'active' => $runningRun !== null,
            'progress' => $progress,
            'message' => $message,
            'run' => $runningRun ?? $lastRun,
            'logs' => $logs,
            'updated_at' => date(DATE_ATOM),
        ]);
    }

    public function progressStatus(): JsonResponse
    {
        $this->reconcileGhostBackupRuns();
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
        $activeJob = $this->managerStore->getRuntimeOption('backup_active_job', null);

        if ($runningRun !== null && is_array($activeJob) && (string) ($activeJob['status'] ?? '') === 'running') {
            $pid = (int) ($activeJob['pid'] ?? 0);
            if ($pid > 0 && !$this->isProcessRunning($pid)) {
                $this->stateStore->updateRunStatus((int) $runningRun['id'], 'failed', ['message' => 'Processo de backup finalizado inesperadamente.']);
                $this->stateStore->addRunLog((int) $runningRun['id'], 'error', 'Backup interrompido: processo não está mais ativo.', ['pid' => $pid]);
                $this->managerStore->setRuntimeOption('backup_active_job', [
                    'run_id' => (int) $runningRun['id'],
                    'type' => (string) ($activeJob['type'] ?? 'manual'),
                    'pid' => $pid,
                    'status' => 'failed',
                    'finished_at' => date(DATE_ATOM),
                    'error' => 'Processo não encontrado durante monitoramento.',
                ]);
                $runningRun = null;
                $active = false;
            }
        }

        if ($runningRun !== null && is_array($activeJob)) {
            $jobStatus = (string) ($activeJob['status'] ?? '');
            $jobRunId = (int) ($activeJob['run_id'] ?? 0);
            if ($jobRunId === (int) ($runningRun['id'] ?? 0) && in_array($jobStatus, ['finished', 'failed', 'cancelled'], true)) {
                if ($jobStatus === 'finished') {
                    $this->stateStore->finishRun((int) $runningRun['id'], ['revision_before' => null, 'revision_after' => null]);
                } elseif ($jobStatus === 'failed') {
                    $this->stateStore->updateRunStatus((int) $runningRun['id'], 'failed', ['message' => (string) ($activeJob['error'] ?? 'Falha no processamento do backup.')]);
                } else {
                    $this->stateStore->updateRunStatus((int) $runningRun['id'], 'cancelled', ['message' => 'Cancelado manualmente.']);
                }

                $runningRun = null;
                $active = false;
                $lastStmt = $this->stateStore->pdo()->prepare("SELECT * FROM runs WHERE options_json LIKE :q ORDER BY id DESC LIMIT 1");
                $lastStmt->execute([':q' => '%manual_backup%']);
                $lastRun = $lastStmt->fetch(\PDO::FETCH_ASSOC) ?: $lastRun;
            }
        }

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
            'active_job' => $activeJob,
            'can_cancel' => $active && is_array($activeJob),
        ]);
    }

    public function seedsIndex()
    {
        return view('laravel-updater::sections.seeds', ['seeds' => $this->stateStore->listSeedRegistry()]);
    }

    public function reapplySeed(Request $request): RedirectResponse
    {
        $actor = request()->attributes->get('updater_user');
        abort_if(!is_array($actor), 403);

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
            $full = $this->createFullBackup($runId, $this->resolveActiveBackupOptions());
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



    private function requiresFullBackupBeforeUpdate(): bool
    {
        if ((bool) config('updater.backup.full_before_update', false)) {
            return true;
        }

        if (!(bool) config('updater.backup.pre_update', true)) {
            return false;
        }

        $type = strtolower(str_replace(' ', '', (string) config('updater.backup.pre_update_type', 'full')));

        return in_array($type, ['full', 'full+snapshot', 'full+database'], true);
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

    private function createDatabaseBackup(int $runId, array $options = []): array
    {
        $name = 'manual-db-' . date('Ymd-His');
        $filePath = $this->backupDriver->backup($name);
        $filePath = $this->compressSingleFileIfNeeded($filePath, (string) ($options['compression'] ?? 'zip'), 'database');

        return $this->insertBackupRow('database', $filePath, $runId);
    }

    private function createSnapshotBackup(int $runId, ?string $compression = null, ?bool $includeVendor = null): array
    {
        $snapshotPath = rtrim((string) config('updater.snapshot.path'), '/');
        $this->fileManager->ensureDirectory($snapshotPath);

        $basePath = $snapshotPath . '/manual-snapshot-' . date('Ymd-His');
        $excludes = config('updater.paths.exclude_snapshot', []);
        $resolvedIncludeVendor = $includeVendor ?? (bool) config('updater.snapshot.include_vendor', false);
        if (!$resolvedIncludeVendor) {
            $excludes[] = 'vendor';
        }
        $resolvedCompression = $compression ?? (string) (config('updater.snapshot.compression', 'zip'));
        $filePath = $this->archiveManager->createArchiveFromDirectory(base_path(), $basePath, $resolvedCompression, array_values(array_unique($excludes)));

        return $this->insertBackupRow('snapshot', $filePath, $runId);
    }

    private function createFullBackup(int $runId, array $options = []): array
    {
        $compression = (string) ($options['compression'] ?? config('updater.snapshot.compression', 'zip'));
        $includeVendor = (bool) ($options['include_vendor'] ?? config('updater.snapshot.include_vendor', false));

        $db = $this->createDatabaseBackup($runId, $options);
        $snapshot = $this->createSnapshotBackup($runId, $compression, $includeVendor);

        $fullBase = rtrim((string) config('updater.backup.path'), '/') . '/manual-full-' . date('Ymd-His');
        $tmpDir = sys_get_temp_dir() . '/updater-full-' . uniqid();
        @mkdir($tmpDir, 0777, true);
        @mkdir($tmpDir . '/database', 0777, true);
        @mkdir($tmpDir . '/snapshot', 0777, true);
        @copy((string) $db['path'], $tmpDir . '/database/' . basename((string) $db['path']));
        @copy((string) $snapshot['path'], $tmpDir . '/snapshot/' . basename((string) $snapshot['path']));

        $fullPath = $this->archiveManager->createArchiveFromDirectory($tmpDir, $fullBase, $compression, []);

        return $this->insertBackupRow('full', $fullPath, $runId);
    }


    private function resolveActiveBackupOptions(): array
    {
        $profile = $this->managerStore->activeProfile();

        return [
            'compression' => (string) ($profile['snapshot_compression'] ?? config('updater.snapshot.compression', 'zip')),
            'include_vendor' => (bool) ($profile['snapshot_include_vendor'] ?? config('updater.snapshot.include_vendor', false)),
        ];
    }

    private function compressSingleFileIfNeeded(string $path, string $compression, string $prefix): string
    {
        $compression = strtolower(trim($compression));
        if (!is_file($path) || in_array($compression, ['', 'none'], true)) {
            return $path;
        }

        $tmpDir = sys_get_temp_dir() . '/updater-single-' . uniqid();
        @mkdir($tmpDir, 0777, true);
        @copy($path, $tmpDir . '/' . basename($path));

        $baseName = dirname($path) . '/' . $prefix . '-compressed-' . date('Ymd-His');

        return $this->archiveManager->createArchiveFromDirectory($tmpDir, $baseName, $compression, []);
    }

    private function insertBackupRow(string $type, string $path, int $runId): array
    {
        $stmt = $this->stateStore->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, run_id, cloud_uploaded, cloud_upload_count) VALUES (:type,:path,:size,:created_at,:run_id,0,0)');
        $stmt->execute([
            ':type' => $type,
            ':path' => $path,
            ':size' => is_file($path) ? (int) filesize($path) : 0,
            ':created_at' => date(DATE_ATOM),
            ':run_id' => $runId,
        ]);

        $upload = $this->managerStore->backupUploadSettings();
        $prefix = trim((string) ($upload['prefix'] ?? config('updater.backup.upload_prefix', 'updater/backups')), '/');
        $autoUpload = (bool) ($upload['auto_upload'] ?? false);
        $backupId = (int) $this->stateStore->pdo()->lastInsertId();
        if ($autoUpload) {
            $this->tryUploadBackupFile($path, $prefix, false, $backupId);
        }

        return [
            'id' => $backupId,
            'type' => $type,
            'path' => $path,
        ];
    }

    public function uploadBackup(int $id, Request $request, TriggerDispatcher $dispatcher): RedirectResponse|JsonResponse
    {
        $backup = $this->findBackup($id);
        abort_if($backup === null, 404);

        $upload = $this->managerStore->backupUploadSettings();
        if ((string) ($upload['provider'] ?? 'none') === 'none') {
            return back()->withErrors(['backup' => 'Nenhum provedor de nuvem configurado para upload.']);
        }

        $path = (string) ($backup['path'] ?? '');
        if (!is_file($path)) {
            return back()->withErrors(['backup' => 'Arquivo local não encontrado para upload manual.']);
        }

        $respondJson = $request->expectsJson() || $request->ajax();

        try {
            $result = $dispatcher->triggerBackupUpload($id);
            $job = [
                'backup_id' => $id,
                'pid' => $result['pid'] ?? null,
                'status' => 'running',
                'progress' => 10,
                'message' => 'Upload iniciado em segundo plano.',
                'started_at' => date(DATE_ATOM),
            ];
            $this->persistUploadJob($id, $job);
            $this->managerStore->addAuditLog($this->actorId($request), 'backup_upload_manual_start', [
                'backup_id' => $id,
                'provider' => $upload['provider'] ?? 'none',
            ], $request->ip(), $request->userAgent());
        } catch (\Throwable $e) {
            try {
                $result = $this->tryUploadBackupFile($path, trim((string) ($upload['prefix'] ?? 'updater/backups'), '/'), true, $id);
                $syncJob = [
                    'backup_id' => $id,
                    'pid' => null,
                    'status' => 'finished',
                    'progress' => 100,
                    'message' => 'Upload concluído em modo síncrono.',
                    'finished_at' => date(DATE_ATOM),
                ];
                $this->persistUploadJob($id, $syncJob);

                if ($respondJson) {
                    return response()->json(['ok' => true, 'message' => 'Upload concluído em modo síncrono.']);
                }

                return back()->with('status', 'Upload concluído em modo síncrono.');
            } catch (\Throwable $syncError) {
                if ($respondJson) {
                    return response()->json(['ok' => false, 'message' => 'Falha ao iniciar upload: ' . $syncError->getMessage()], 422);
                }

                return back()->withErrors(['backup' => 'Falha ao iniciar upload: ' . $syncError->getMessage()]);
            }
        }

        if ($respondJson) {
            return response()->json(['ok' => true, 'message' => 'Upload iniciado em segundo plano.']);
        }

        return back()->with('status', 'Upload iniciado em segundo plano.');
    }

    public function uploadProgressStatus(): JsonResponse
    {
        $job = $this->managerStore->getRuntimeOption('backup_upload_active_job', null);
        if (is_array($job) && (string) ($job['status'] ?? '') === 'running') {
            $pid = (int) ($job['pid'] ?? 0);
            if ($pid > 0 && !$this->isProcessRunning($pid)) {
                $job['status'] = 'failed';
                $job['progress'] = 100;
                $job['message'] = 'Upload interrompido: processo não encontrado.';
                $job['finished_at'] = date(DATE_ATOM);
                $this->managerStore->setRuntimeOption('backup_upload_active_job', $job);
            }
        }

        $job = $this->managerStore->getRuntimeOption('backup_upload_active_job', null);
        $jobs = $this->managerStore->getRuntimeOption('backup_upload_jobs', []);
        if (!is_array($jobs)) {
            $jobs = [];
        }

        foreach ($jobs as $backupId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string) ($entry['status'] ?? '') !== 'running') {
                continue;
            }

            $pid = (int) ($entry['pid'] ?? 0);
            if ($pid > 0 && !$this->isProcessRunning($pid)) {
                $entry['status'] = 'failed';
                $entry['progress'] = 100;
                $entry['message'] = 'Upload interrompido: processo não encontrado.';
                $entry['finished_at'] = date(DATE_ATOM);
                $jobs[(string) $backupId] = $entry;
            }
        }

        $this->managerStore->setRuntimeOption('backup_upload_jobs', $jobs);
        if (is_array($job) && isset($job['backup_id'])) {
            $activeKey = (string) ((int) $job['backup_id']);
            if (isset($jobs[$activeKey]) && is_array($jobs[$activeKey])) {
                $job = $jobs[$activeKey];
                $this->managerStore->setRuntimeOption('backup_upload_active_job', $job);
            }
        }

        return response()->json([
            'active' => is_array($job) && (string) ($job['status'] ?? '') === 'running',
            'job' => $job,
            'jobs' => $jobs,
            'progress' => (int) ($job['progress'] ?? 0),
            'message' => (string) ($job['message'] ?? 'Sem upload em andamento.'),
            'can_cancel' => is_array($job) && (string) ($job['status'] ?? '') === 'running',
            'updated_at' => date(DATE_ATOM),
        ]);
    }

    public function cancelUpload(Request $request): RedirectResponse
    {
        $job = $this->managerStore->getRuntimeOption('backup_upload_active_job', null);
        if (!is_array($job) || (string) ($job['status'] ?? '') !== 'running') {
            return back()->withErrors(['backup' => 'Não existe upload em andamento para cancelar.']);
        }

        $pid = (int) ($job['pid'] ?? 0);
        if ($pid > 0) {
            $this->terminatePid($pid);
        }

        $job['status'] = 'cancelled';
        $job['progress'] = 100;
        $job['message'] = 'Upload cancelado manualmente.';
        $job['cancelled_at'] = date(DATE_ATOM);

        $backupId = (int) ($job['backup_id'] ?? 0);
        $this->persistUploadJob($backupId, $job);

        if ($backupId > 0) {
            $stmt = $this->stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_last_error = :error WHERE id = :id');
            $stmt->execute([':error' => 'Upload cancelado manualmente.', ':id' => $backupId]);
        }

        return back()->with('status', 'Upload cancelado com sucesso.');
    }

    private function tryUploadBackupFile(string $path, string $prefix, bool $throwOnFailure = false, ?int $backupId = null): array
    {
        if (!is_file($path)) {
            return [];
        }

        $settings = $this->managerStore->backupUploadSettings();
        if (trim((string) ($settings['provider'] ?? 'none')) === 'none') {
            return [];
        }

        $settings['prefix'] = $prefix;

        try {
            $result = $this->cloudUploader->upload($path, $settings);
            $provider = (string) ($result['provider'] ?? ($settings['provider'] ?? 'n/a'));
            $expectedProvider = (string) ($settings['provider'] ?? 'none');
            if ($expectedProvider !== 'none' && !str_starts_with($provider, $expectedProvider === 'minio' ? 's3' : $expectedProvider)) {
                throw new RuntimeException('Upload retornou provedor inesperado: ' . $provider);
            }

            if ($backupId !== null) {
                $stmt = $this->stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_uploaded = 1, cloud_provider = :provider, cloud_uploaded_at = :uploaded_at, cloud_remote_path = :remote_path, cloud_upload_count = COALESCE(cloud_upload_count, 0) + 1, cloud_last_error = NULL WHERE id = :id');
                $stmt->execute([
                    ':provider' => $provider,
                    ':uploaded_at' => date(DATE_ATOM),
                    ':remote_path' => (string) ($result['remote_path'] ?? ''),
                    ':id' => $backupId,
                ]);
            }

            $this->stateStore->addRunLog(null, 'info', 'Backup enviado para nuvem com sucesso.', [
                'provider' => $provider,
                'arquivo' => $path,
                'remoto' => $result['remote_path'] ?? null,
                'backup_id' => $backupId,
            ]);

            return [
                'provider' => $provider,
                'remote_path' => (string) ($result['remote_path'] ?? ''),
            ];
        } catch (\Throwable $e) {
            if ($backupId !== null) {
                $stmt = $this->stateStore->pdo()->prepare('UPDATE updater_backups SET cloud_last_error = :error WHERE id = :id');
                $stmt->execute([':error' => $e->getMessage(), ':id' => $backupId]);
            }
            if ($throwOnFailure) {
                throw $e;
            }
            $this->stateStore->addRunLog(null, 'warning', 'Upload em nuvem falhou, mas o backup foi concluído localmente.', [
                'provider' => $settings['provider'] ?? 'n/a',
                'arquivo' => $path,
                'erro' => $e->getMessage(),
            ]);

            return [];
        }
    }


    private function persistUploadJob(int $backupId, array $job): void
    {
        if ($backupId <= 0) {
            return;
        }

        $this->managerStore->setRuntimeOption('backup_upload_active_job', $job);
        $jobs = $this->managerStore->getRuntimeOption('backup_upload_jobs', []);
        if (!is_array($jobs)) {
            $jobs = [];
        }

        $jobs[(string) $backupId] = $job;
        $this->managerStore->setRuntimeOption('backup_upload_jobs', $jobs);
    }

    public function deleteBackup(int $id, Request $request): RedirectResponse
    {
        $backup = $this->findBackup($id);
        abort_if($backup === null, 404);

        $path = (string) ($backup['path'] ?? '');
        if ($path !== '' && is_file($path) && is_writable($path)) {
            @unlink($path);
        }

        $stmt = $this->stateStore->pdo()->prepare('DELETE FROM updater_backups WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $this->managerStore->addAuditLog($this->actorId($request), 'backup_delete', [
            'backup_id' => $id,
            'tipo' => $backup['type'] ?? null,
            'path' => $path,
        ], $request->ip(), $request->userAgent());

        return back()->with('status', 'Backup removido com sucesso.');
    }

    public function cancelBackup(Request $request): RedirectResponse
    {
        $this->reconcileGhostBackupRuns();
        $activeJob = $this->managerStore->getRuntimeOption('backup_active_job', null);
        if (!is_array($activeJob) || (string) ($activeJob['status'] ?? '') !== 'running') {
            return back()->withErrors(['backup' => 'Não existe backup em execução para cancelar.']);
        }

        $runId = (int) ($activeJob['run_id'] ?? 0);
        $pid = (int) ($activeJob['pid'] ?? 0);
        if ($pid > 0) {
            $this->terminatePid($pid);
        }

        if ($runId > 0) {
            $this->stateStore->updateRunStatus($runId, 'cancelled', ['message' => 'Cancelado manualmente via UI.']);
            $this->stateStore->addRunLog($runId, 'warning', 'Backup cancelado manualmente pelo usuário.', [
                'pid' => $pid > 0 ? $pid : null,
            ]);
        }

        $this->managerStore->setRuntimeOption('backup_active_job', [
            'run_id' => $runId,
            'type' => (string) ($activeJob['type'] ?? 'manual'),
            'pid' => $pid > 0 ? $pid : null,
            'status' => 'cancelled',
            'cancelled_at' => date(DATE_ATOM),
        ]);

        $this->managerStore->addAuditLog($this->actorId($request), 'backup_cancel', [
            'run_id' => $runId,
            'pid' => $pid > 0 ? $pid : null,
        ], $request->ip(), $request->userAgent());

        return back()->with('status', 'Solicitação de cancelamento enviada para o backup em andamento.');
    }


    private function reconcileGhostBackupRuns(): void
    {
        $runningStmt = $this->stateStore->pdo()->prepare("SELECT * FROM runs WHERE options_json LIKE :q AND status = 'running' ORDER BY id ASC");
        $runningStmt->execute([':q' => '%manual_backup%']);
        $rows = $runningStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $activeJob = $this->managerStore->getRuntimeOption('backup_active_job', null);
        $activeRunId = is_array($activeJob) ? (int) ($activeJob['run_id'] ?? 0) : 0;
        $activePid = is_array($activeJob) ? (int) ($activeJob['pid'] ?? 0) : 0;

        foreach ($rows as $run) {
            $runId = (int) ($run['id'] ?? 0);
            if ($runId <= 0) {
                continue;
            }

            if ($runId === $activeRunId && $activePid > 0 && $this->isProcessRunning($activePid)) {
                continue;
            }

            $startedAt = strtotime((string) ($run['started_at'] ?? '')) ?: 0;
            if ($startedAt > 0 && (time() - $startedAt) < 120) {
                continue;
            }

            $this->stateStore->updateRunStatus($runId, 'failed', ['message' => 'Run fantasma reconciliada automaticamente.']);
            $this->stateStore->addRunLog($runId, 'warning', 'Run de backup marcada como falha por não existir processo ativo.', []);
        }
    }


    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            @exec('tasklist /FI "PID eq ' . (int) $pid . '"', $output);
            return str_contains(strtolower(implode('\n', $output)), (string) $pid);
        }

        $output = [];
        @exec('ps -p ' . (int) $pid . ' -o pid=', $output);

        return trim(implode('', $output)) !== '';
    }

    private function terminatePid(int $pid): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
            usleep(200000);
            @posix_kill($pid, 9);
            return;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec('taskkill /F /PID ' . (int) $pid);
            return;
        }

        @exec('kill -TERM ' . (int) $pid . ' >/dev/null 2>&1');
        usleep(200000);
        @exec('kill -KILL ' . (int) $pid . ' >/dev/null 2>&1');
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
