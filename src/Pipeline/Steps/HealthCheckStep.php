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
        $configuredUrl = trim((string) ($this->config['url'] ?? ''));
        if ($configuredUrl === '') {
            return;
        }

        [$url, $internal] = $this->resolveTarget($configuredUrl, $context);

        if ($this->shouldSkipForLocalhost($url)) {
            $context['healthcheck_warning'][] = ['url' => $this->safeUrl($url), 'reason' => 'localhost_skipped'];
            return;
        }

        $timeout = max(1, (int) ($this->config['timeout'] ?? 5));
        $result = $this->request($url, $timeout);
        $status = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? false;

        // Respeita o contrato anterior: endpoints personalizados precisam responder HTTP 200.
        $healthy = $status === 200 && $body !== false;

        if ($healthy && $internal) {
            // Evita aceitar página de login, CDN ou HTML de manutenção com HTTP 200.
            $payload = json_decode((string) $body, true);
            $healthy = is_array($payload)
                && ($payload['updater_health'] ?? false) === true
                && ($payload['status'] ?? null) === 'ok';
        }

        $context['healthcheck'] = [
            'url' => $this->safeUrl($url),
            'mode' => $internal ? 'updater_readiness' : 'configured_url',
            'http_status' => $status,
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'healthy' => $healthy,
        ];

        if ($healthy) {
            return;
        }

        $reason = $status === 0 ? 'falha de conexão'
            : ($internal && $status === 404 ? 'rota de saúde não encontrada (verifique route:cache)'
            : ($internal && $status === 200 ? 'resposta de saúde inválida'
            : 'resposta HTTP inesperada'));

        throw new UpdaterException(sprintf(
            'Healthcheck falhou em %s (HTTP %s; %s; %d ms; %s)',
            $this->safeUrl($url),
            $status > 0 ? (string) $status : 'sem resposta',
            $internal ? 'updater_readiness' : 'configured_url',
            (int) ($result['duration_ms'] ?? 0),
            $reason
        ));
    }

    public function rollback(array &$context): void
    {
    }

    /**
     * Configurações publicadas antigas apontam para APP_URL, que devolve 503
     * durante a manutenção. Nesse caso usamos automaticamente a rota do updater.
     * URLs personalizadas continuam sendo verificadas sem qualquer alteração.
     *
     * @return array{0:string,1:bool}
     */
    private function resolveTarget(string $configuredUrl, array $context): array
    {
        $appUrl = rtrim(trim((string) config('app.url', '')), '/');
        $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');

        if ($appUrl === '' || $prefix === '') {
            return [$configuredUrl, false];
        }

        $internalUrl = $appUrl . '/' . $prefix . '/health';
        if (
            (bool) ($context['maintenance'] ?? false)
            && rtrim($configuredUrl, '/') === $appUrl
        ) {
            return [$internalUrl, true];
        }

        return [$configuredUrl, rtrim($configuredUrl, '/') === $internalUrl];
    }

    /**
     * @return array{status:int,body:string|false,duration_ms:int}
     */
    protected function request(string $url, int $timeout): array
    {
        $startedAt = microtime(true);
        $options = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => "Accept: application/json\r\nCache-Control: no-cache\r\n",
            ],
        ]);

        // $http_response_header pertence ao escopo em que a chamada HTTP ocorre.
        $body = @file_get_contents($url, false, $options);
        $statusLine = (string) ($http_response_header[0] ?? '');
        $status = preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/i', $statusLine, $matches) === 1
            ? (int) $matches[1]
            : 0;

        return [
            'status' => $status,
            'body' => $body,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '[URL inválida]';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
    }

    private function shouldSkipForLocalhost(string $url): bool
    {
        if (!((bool) ($this->config['skip_localhost'] ?? true))) {
            return false;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
