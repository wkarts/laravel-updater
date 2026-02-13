<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Commands;

use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class UpdateNotifyCommand extends Command
{
    protected $signature = 'system:update:notify {--force : envia mesmo se já enviado} {--allow-dirty}';
    protected $description = 'Envia notificação por e-mail quando houver nova atualização disponível.';

    public function handle(UpdaterKernel $kernel, ManagerStore $managerStore, StateStore $store): int
    {
        if (!(bool) env('UPDATER_NOTIFY_ENABLED', false)) {
            $this->warn('Notificação desabilitada (UPDATER_NOTIFY_ENABLED=false).');

            return self::SUCCESS;
        }

        $activeSource = $managerStore->activeSource();
        $activeProfile = $managerStore->activeProfile();
        if ($activeSource === null || $activeProfile === null) {
            $this->warn('Defina uma fonte e um perfil ativos para notificar.');

            return self::INVALID;
        }

        $status = $kernel->check((bool) $this->option('allow-dirty'));
        $latestTag = (string) ($status['latest_tag'] ?? '');
        $remoteRevision = (string) ($status['remote'] ?? '');
        $isAvailable = $latestTag !== ''
            ? ((bool) ($status['has_update_by_tag'] ?? false))
            : ((int) ($status['behind_by_commits'] ?? 0) > 0);

        if (!$isAvailable && !$this->option('force')) {
            $this->info('Sem novas atualizações disponíveis.');

            return self::SUCCESS;
        }

        $notifyKey = $latestTag !== '' ? 'tag:' . $latestTag : 'rev:' . $remoteRevision;
        $last = $store->lastNotification((int) $activeSource['id'], (int) $activeProfile['id']);
        if (!$this->option('force') && is_array($last) && ($last['last_notified_key'] ?? '') === $notifyKey) {
            $this->info('Notificação já enviada anteriormente para esta versão.');

            return self::SUCCESS;
        }

        $rawRecipients = (string) (config('updater.notify.to', '') ?: env('UPDATER_NOTIFY_TO') ?: env('UPDATER_REPORT_TO', ''));
        $recipients = array_values(array_filter(array_map(static fn (string $mail): string => trim($mail), preg_split('/[,;]+/', $rawRecipients) ?: [])));

        if ($recipients === []) {
            $this->error('Defina UPDATER_NOTIFY_TO (aceita múltiplos e-mails separados por vírgula) ou UPDATER_REPORT_TO.');

            return self::FAILURE;
        }

        $subjectVersion = $latestTag !== '' ? $latestTag : mb_substr($remoteRevision, 0, 12);
        $subject = '[Updater] Nova atualização disponível: ' . $subjectVersion;

        $body = implode("\n", [
            'Aplicação: ' . config('app.name', 'Laravel'),
            'Ambiente: ' . config('app.env', 'production'),
            'Fonte ativa: ' . ($activeSource['name'] ?? '-'),
            'Perfil ativo: ' . ($activeProfile['name'] ?? '-'),
            'Tag atual/alvo: ' . ($latestTag !== '' ? $latestTag : '-'),
            'Revisão remota: ' . $remoteRevision,
            'Acesse a UI do updater para revisar e executar: ' . url((string) config('updater.ui.prefix', '_updater')),
        ]);

        Mail::raw($body, function ($message) use ($recipients, $subject): void {
            $message->to($recipients)->subject($subject);
        });

        $store->saveNotification((int) $activeSource['id'], (int) $activeProfile['id'], $notifyKey);
        $this->info('Notificação enviada para: ' . implode(', ', $recipients));

        return self::SUCCESS;
    }
}
