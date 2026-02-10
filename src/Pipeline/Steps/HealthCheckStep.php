<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Exceptions\UpdaterException;

class HealthCheckStep implements PipelineStepInterface
{
    public function __construct(private readonly array $config)
    {
    }

    public function name(): string { return 'health_check'; }
    public function shouldRun(array $context): bool { return (bool) ($this->config['enabled'] ?? false); }

    public function handle(array &$context): void
    {
        $url = $this->config['url'];
        $timeout = (int) ($this->config['timeout'] ?? 5);
        $contextOptions = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $content = @file_get_contents($url, false, $contextOptions);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';

        if (!str_contains($statusLine, '200') || $content === false) {
            throw new UpdaterException('Healthcheck falhou em ' . $url);
        }
    }

    public function rollback(array &$context): void
    {
    }
}
