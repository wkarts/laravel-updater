<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Support\Facades\Mail;

class RunReportMailer
{
    public function sendIfEnabled(array $runContext, string $status): void
    {
        if (!(bool) config('updater.report.enabled', false)) {
            return;
        }

        $on = (string) config('updater.report.on', 'failure');
        if ($on === 'success' && $status !== 'success') {
            return;
        }
        if ($on === 'failure' && $status === 'success') {
            return;
        }

        $to = array_filter(array_map('trim', explode(',', (string) config('updater.report.to', ''))));
        if ($to === []) {
            return;
        }

        $prefix = (string) config('updater.report.subject_prefix', '[Updater]');
        $subject = sprintf('%s Run #%s - %s', $prefix, (string) ($runContext['run_id'] ?? '-'), mb_strtoupper($status));

        $body = "Aplicação: " . config('app.name') . "\n";
        $body .= "Ambiente: " . config('app.env') . "\n";
        $body .= "Run ID: " . ($runContext['run_id'] ?? '-') . "\n";
        $body .= "Status: " . $status . "\n";
        $body .= "Revisão antes: " . ($runContext['revision_before'] ?? '-') . "\n";
        $body .= "Revisão depois: " . ($runContext['revision_after'] ?? '-') . "\n";

        Mail::raw($body, function ($message) use ($to, $subject): void {
            $message->to($to)->subject($subject);
        });
    }
}
