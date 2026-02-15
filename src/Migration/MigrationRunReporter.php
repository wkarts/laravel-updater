<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Migration;

use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Support\Facades\Log;

class MigrationRunReporter
{
    /** @var array<int,array<string,mixed>> */
    private array $events = [];

    public function __construct(
        private readonly ?StateStore $store,
        private readonly string $logFile,
        private readonly ?int $runId = null,
        private readonly string $logChannel = 'stack'
    ) {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $this->events[] = $payload;

        @file_put_contents($this->logFile, json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

        if (class_exists(Log::class)) {
            try {
                Log::channel($this->logChannel)->log($level, $message, $context);
            } catch (\Throwable) {
                // sem impacto no fluxo principal
            }
        }

        $this->store?->addRunLog($this->runId, $level, $message, $context);
    }

    public function summary(array $stats): array
    {
        return [
            'stats' => $stats,
            'events' => $this->events,
            'log_file' => $this->logFile,
            'log_channel' => $this->logChannel,
        ];
    }
}
