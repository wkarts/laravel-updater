<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Auditoria da própria execução do updater, sem coletar logs do ERP hospedeiro.
 * Formato retrocompatível: lê as tabelas SQLite já utilizadas pelo pacote.
 */
final class RunDiagnosticsService
{
    public const MAX_EVENTS = 5000;

    public function __construct(private readonly StateStore $store)
    {
    }

    /** @return array<string,mixed>|null */
    public function report(int $runId): ?array
    {
        $run = $this->store->findRun($runId);
        if ($run === null) {
            return null;
        }

        $db = $this->store->pdo();
        $counter = $db->prepare('SELECT COUNT(*) FROM updater_logs WHERE run_id = :run_id');
        $counter->execute([':run_id' => $runId]);
        $eventCount = (int) $counter->fetchColumn();

        $stmt = $db->prepare(
            'SELECT id, run_id, created_at, level, message, context_json
             FROM updater_logs WHERE run_id = :run_id ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':run_id', $runId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', self::MAX_EVENTS, PDO::PARAM_INT);
        $stmt->execute();

        $events = [];
        foreach (array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rawContext = json_decode((string) ($row['context_json'] ?? ''), true);
            $context = is_array($rawContext) ? self::sanitize($rawContext) : [];
            $events[] = [
                'id' => (int) $row['id'],
                'timestamp' => (string) $row['created_at'],
                'level' => (string) $row['level'],
                'message' => self::sanitize((string) $row['message']),
                'step' => (string) ($context['etapa'] ?? $context['step'] ?? ''),
                'context' => $context,
            ];
        }

        $stages = [];
        $firstFailedStep = null;
        $errors = 0;
        $warnings = 0;
        $rollbacks = 0;
        foreach ($events as $event) {
            $level = strtolower($event['level']);
            if (in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $errors++;
            }
            if ($level === 'warning') {
                $warnings++;
            }

            $step = $event['step'];
            if ($step === '') {
                continue;
            }

            $stages[$step] ??= [
                'step' => $step,
                'status' => 'unknown',
                'rollback' => null,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'details' => [],
            ];
            $stage = &$stages[$step];
            $type = (string) ($event['context']['event'] ?? $this->eventType((string) $event['message']));
            if ($type === 'start') {
                $stage['started_at'] = $event['timestamp'];
                $stage['status'] = 'running';
            } elseif ($type === 'success' || $type === 'failure') {
                $stage['finished_at'] = $event['timestamp'];
                $stage['status'] = $type === 'success' ? 'success' : 'failed';
                if (isset($event['context']['duration_ms'])) {
                    $stage['duration_ms'] = (int) $event['context']['duration_ms'];
                } elseif ($stage['started_at']) {
                    $stage['duration_ms'] = $this->elapsedMs($stage['started_at'], $stage['finished_at']);
                }
                if (isset($event['context']['details']) && is_array($event['context']['details'])) {
                    $stage['details'] = $event['context']['details'];
                }
                if ($type === 'failure' && $firstFailedStep === null) {
                    $firstFailedStep = $step;
                }
            } elseif ($type === 'rollback' || $type === 'rollback_failure') {
                $stage['rollback'] = $type === 'rollback' ? 'applied' : 'failed';
                $rollbacks++;
            }
            unset($stage);
        }

        $error = json_decode((string) ($run['error_json'] ?? ''), true);
        $error = is_array($error) ? self::sanitize($error) : [];
        if (empty($error['message'])) {
            foreach ($events as $event) {
                if ($this->eventType((string) $event['message']) === 'failure') {
                    $error['message'] = $event['context']['erro'] ?? $event['message'];
                    break;
                }
            }
        }

        $options = json_decode((string) ($run['options_json'] ?? ''), true);
        $migrations = [];
        foreach (array_reverse($this->store->listMigrationAttempts(null, $runId, 2000)) as $attempt) {
            $attemptContext = json_decode((string) ($attempt['context_json'] ?? ''), true);
            unset($attempt['context_json']);
            $attempt['context'] = self::sanitize(is_array($attemptContext) ? $attemptContext : []);
            $migrations[] = self::sanitize($attempt);
        }

        return [
            'format_version' => 1,
            'generated_at' => date(DATE_ATOM),
            'source' => 'argws/laravel-updater',
            'run' => self::sanitize([
                'id' => (int) $run['id'],
                'status' => $run['status'],
                'started_at' => $run['started_at'],
                'finished_at' => $run['finished_at'],
                'revision_before' => $run['revision_before'],
                'revision_after' => $run['revision_after'],
                'backup_file' => $run['backup_file'],
                'snapshot_file' => $run['snapshot_file'],
                'worker_pid' => $run['worker_pid'] ?? null,
                'execution_mode' => $run['execution_mode'] ?? null,
                'current_step' => $run['current_step'] ?? null,
            ]),
            'options' => self::sanitize(is_array($options) ? $options : []),
            'error' => $error,
            'summary' => [
                'duration_ms' => $this->elapsedMs($run['started_at'], $run['finished_at'] ?? null),
                'event_count' => $eventCount,
                'included_events' => count($events),
                'truncated_events' => $eventCount > self::MAX_EVENTS,
                'error_count' => $errors,
                'warning_count' => $warnings,
                'rollback_events' => $rollbacks,
                'first_failed_step' => $firstFailedStep,
                'migration_attempt_count' => count($migrations),
            ],
            'stages' => array_values($stages),
            'events' => $events,
            'migration_attempts' => $migrations,
        ];
    }

    /**
     * Adiciona aos eventos apenas os detalhes pertinentes à etapa.
     * Não persiste novamente o contexto completo, que pode conter credenciais.
     *
     * @return array<string,mixed>
     */
    public static function stageDetails(string $step, array $context): array
    {
        $fields = match ($step) {
            'backup_full' => ['backup_file', 'full_backup_file'],
            'git_update' => ['revision_before', 'revision_after', 'git_tag_before', 'git_tag_after', 'git_update_log'],
            'psr4_sanitize' => ['psr4_sanitized_count', 'psr4_blockers'],
            'composer_install' => ['composer_install_warning', 'composer_install_result'],
            'migrate' => ['migrate_strategy', 'migrate_service_fallback_error'],
            'seed' => ['seed_log'],
            'cache_clear' => ['cache_clear_warning', 'cache_clear_commands'],
            'post_update_commands' => ['post_update_commands_log', 'post_update_command_results'],
            'health_check' => ['healthcheck', 'healthcheck_warning'],
            default => [],
        };

        $details = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $context)) {
                $details[$field] = $context[$field];
            }
        }

        return self::sanitize($details);
    }

    public function toJson(array $report): string
    {
        return json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        ) . "\n";
    }

    /**
     * Cria arquivo temporário próprio, removível após response()->download().
     * Nunca inclui .env, banco SQL, backups ou arquivos arbitrários da aplicação.
     */
    public function toZip(array $report): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extensão PHP zip indisponível para exportar relatório.');
        }

        $path = tempnam(sys_get_temp_dir(), 'updater-report-');
        if ($path === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário do relatório.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Não foi possível abrir arquivo ZIP do relatório.');
        }

        try {
            $zip->addFromString('diagnostico.json', $this->toJson($report));

            $events = [];
            foreach ($report['events'] as $event) {
                $events[] = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $zip->addFromString('eventos.jsonl', implode("\n", $events) . "\n");
            $zip->addFromString('etapas.json', $this->toJson($report['stages']));
            $zip->addFromString('migrations.json', $this->toJson($report['migration_attempts']));
            $zip->addFromString('README.txt',
                "Relatório do Laravel Updater\n" .
                "Run #" . $report['run']['id'] . "\n" .
                "Inclui registros SQLite associados à execução, etapas, erros e tentativas de migrations.\n" .
                "Não inclui logs do ERP nem segredos, backups ou arquivos da aplicação.\n" .
                "Eventos limitados aos últimos " . self::MAX_EVENTS . "; consulte truncated_events no resumo.\n"
            );
        } finally {
            if (!$zip->close()) {
                @unlink($path);
                throw new RuntimeException('Falha ao finalizar o ZIP do diagnóstico.');
            }
        }

        return $path;
    }

    public static function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 9) {
            return '[limite de profundidade]';
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                // Argumentos arbitrários de comandos podem conter segredos sem rótulo.
                // Registra o comando-base, mas nunca seus argumentos inteiros.
                if (in_array($key, ['pre_update_commands', 'post_update_commands', 'post_update_commands_log'], true)) {
                    $items = is_array($item) ? $item : [$item];
                    $result[$key] = array_map(
                        static fn ($line) => self::commandSummary((string) $line),
                        $items
                    );
                    continue;
                }
                if ($key === 'command' && is_string($item)) {
                    $result[$key] = self::commandSummary($item);
                    continue;
                }
                if (is_string($key) && preg_match(
                    '/pass(word)?|token|secret|api[_-]?key|authorization|credential|private[_-]?key|access[_-]?key|cookie|encryption[_-]?key|signature/i',
                    $key
                )) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = self::sanitize($item, $depth + 1);
                }
            }

            return $result;
        }

        if (is_object($value)) {
            return '[objeto omitido]';
        }

        if (!is_string($value)) {
            return $value;
        }

        $value = mb_substr($value, 0, 10000);
        // Strings JSON legadas são desserializadas para higienização por nome de chave.
        if (in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::sanitize($decoded, $depth + 1);
            }
        }
        $value = preg_replace('~(https?://)[^/\s@:]+:[^/\s@]+@~i', '$1[REDACTED]@', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[a-z0-9._~+\/=-]+/i', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace(
            '/\b(password|token|secret|api[_-]?key|client_secret|authorization|access_key|APP_KEY)\s*(=|:)\s*([^\s,;&]+)/i',
            '$1$2[REDACTED]',
            $value
        ) ?? $value;

        return $value;
    }

    private static function commandSummary(string $line): string
    {
        // Argumentos arbitrários podem ser chaves sem rótulo. Mantém só o
        // executável e o subcomando reconhecido; nunca serializa o comando integral.
        $line = preg_replace('/\s+/', ' ', trim($line)) ?? '';
        $parts = preg_split('/\s+/', $line) ?: [];
        if ($parts === []) {
            return '[comando não informado]';
        }

        $head = [basename($parts[0])];
        if (in_array(strtolower($head[0]), ['composer', 'composer2', 'git', 'npm', 'pnpm', 'yarn', 'php'], true)
            && isset($parts[1]) && preg_match('/^[a-z][a-z0-9:_-]*$/i', $parts[1])) {
            $head[] = $parts[1];
            if (strtolower($head[0]) === 'php' && strtolower($parts[1]) === 'artisan'
                && isset($parts[2]) && preg_match('/^[a-z][a-z0-9:_-]*$/i', $parts[2])) {
                $head[] = $parts[2];
            }
        }

        return implode(' ', $head) . (count($parts) > count($head) ? ' [argumentos omitidos]' : '');
    }

    private function eventType(string $message): string
    {
        return match ($message) {
            'Iniciando etapa da atualização.' => 'start',
            'Etapa concluída com sucesso.' => 'success',
            'Falha em etapa da atualização.' => 'failure',
            'Rollback aplicado para etapa.' => 'rollback',
            'Falha no rollback da etapa.' => 'rollback_failure',
            default => '',
        };
    }

    private function elapsedMs(?string $begin, ?string $end): ?int
    {
        if (!$begin || !$end) {
            return null;
        }
        try {
            $start = new DateTimeImmutable($begin);
            $finish = new DateTimeImmutable($end);

            return max(0, (int) round(((float) $finish->format('U.u') - (float) $start->format('U.u')) * 1000));
        } catch (Throwable) {
            return null;
        }
    }
}
