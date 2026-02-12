<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OperationsController extends Controller
{
    public function __construct(private readonly ManagerStore $managerStore, private readonly StateStore $stateStore, private readonly ShellRunner $shell)
    {
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
        $base = (string) config('updater.backup.path');
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }

        $file = $base . '/manual-' . $type . '-' . date('Ymd-His') . '.txt';
        file_put_contents($file, 'Backup manual gerado em ' . date(DATE_ATOM));

        $this->stateStore->pdo()->prepare('INSERT INTO updater_backups (type, path, size, created_at, run_id) VALUES (:type,:path,:size,:created_at,:run_id)')->execute([
            ':type' => $type,
            ':path' => $file,
            ':size' => (int) filesize($file),
            ':created_at' => date(DATE_ATOM),
            ':run_id' => $runId,
        ]);

        $this->stateStore->finishRun($runId, ['revision_before' => null, 'revision_after' => null]);
        $this->managerStore->addAuditLog($this->actorId($request), 'backup', ['tipo' => $type, 'run_id' => $runId], $request->ip(), $request->userAgent());

        return back()->with('status', 'Backup manual gerado com sucesso.');
    }

    public function downloadBackup(int $id)
    {
        $stmt = $this->stateStore->pdo()->prepare('SELECT * FROM updater_backups WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $backup = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        abort_if($backup === null || !is_file((string) $backup['path']), 404);

        return response()->download((string) $backup['path']);
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

        $stmt = $this->stateStore->pdo()->prepare('SELECT * FROM updater_backups WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $backup = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        abort_if($backup === null, 404);
        if (!is_file((string) $backup['path']) || !is_readable((string) $backup['path'])) {
            return back()->withErrors(['restore' => 'Arquivo de backup inexistente ou sem permissão de leitura.']);
        }

        $this->managerStore->addAuditLog((int) $actor['id'], 'restore', ['backup_id' => $id, 'path' => $backup['path']], $request->ip(), $request->userAgent());
        $this->stateStore->addRunLog(null, 'warning', 'Restore solicitado via UI.', ['backup_id' => $id]);

        return back()->with('status', 'Restore registrado com sucesso. Execute o restore técnico conforme seu driver.');
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

    private function actorId(Request $request): ?int
    {
        $user = $request->attributes->get('updater_user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : null;
    }
}
