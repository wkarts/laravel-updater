<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Support\Psr4Sanitizer;
use Argws\LaravelUpdater\Support\StateStore;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Psr4SanitizeStep implements PipelineStepInterface
{
    public function __construct(
        private readonly Psr4Sanitizer $sanitizer,
        private readonly StateStore $store,
        private readonly LoggerInterface $logger
    ) {
    }

    public function name(): string { return 'psr4_sanitize'; }
    public function shouldRun(array $context): bool { return (bool) config('updater.psr4_guard.enabled', true); }

    public function handle(array &$context): void
    {
        $projectPath = function_exists('base_path') ? base_path() : getcwd();
        $config = (array) config('updater.psr4_guard', []);

        $report = $this->sanitizer->run((string) $projectPath, $config);
        $context['psr4_guard'] = $report;
        $context['psr4_sanitized_count'] = count((array) ($report['quarantined_files'] ?? [])) + count((array) ($report['deleted_files'] ?? []));
        $context['psr4_quarantined_files'] = $report['quarantined_files'] ?? [];
        $context['psr4_blockers'] = $report['blockers'] ?? [];

        $runId = is_numeric($context['run_id'] ?? null) ? (int) $context['run_id'] : 0;
        if ($runId > 0) {
            $this->store->addRunLog($runId, 'info', 'Auditoria PSR-4 finalizada.', [
                'checked_files' => (int) ($report['checked_files'] ?? 0),
                'quarantined_count' => count((array) ($report['quarantined_files'] ?? [])),
                'warnings_count' => count((array) ($report['warnings'] ?? [])),
                'blockers_count' => count((array) ($report['blockers'] ?? [])),
            ]);
        }

        $auditPath = (string) config('updater.psr4_guard.audit_log_path', storage_path('logs/updater-psr4-audit.log'));
        $line = [
            'timestamp' => date('c'),
            'run_id' => $runId > 0 ? $runId : null,
            'report' => $report,
        ];

        @file_put_contents($auditPath, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

        $failOnBlocker = (bool) ($config['fail_on_blocker'] ?? false);
        if ($failOnBlocker && count((array) ($report['blockers'] ?? [])) > 0) {
            $this->logger->error('pipeline.psr4_guard.blockers', [
                'count' => count((array) $report['blockers']),
                'blockers' => $report['blockers'],
            ]);

            throw new RuntimeException('PSR-4 guard encontrou bloqueadores e fail_on_blocker=true.');
        }
    }

    public function rollback(array &$context): void
    {
        // Quarentena é ação de proteção e não deve ser revertida automaticamente.
    }
}
