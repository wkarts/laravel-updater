<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Webhooks;

use RuntimeException;

class WebhookTransport
{
    public function __construct(private readonly WebhookDestination $destination)
    {
    }

    public function send(string $url, string $secret, string $eventId, string $body): int
    {
        // Never fall back to an unpinned HTTP client when curl is unavailable.
        if (!function_exists('curl_init')) {
            throw new RuntimeException('transport_unavailable');
        }
        $target = $this->destination->resolve($url);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('transport_unavailable');
        }
        $timestamp = (string) time();
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $received = 0;
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Updater-Timestamp: ' . $timestamp,
                'X-Updater-Event-Id: ' . $eventId,
                'X-Updater-Signature: ' . WebhookSignature::sign($secret, $timestamp, $eventId, $body),
            ],
            CURLOPT_USERAGENT => 'Laravel-Updater-Webhook/1.0',
            CURLOPT_RESOLVE => [$target['host'] . ':443:' . $ip],
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                $received += strlen($chunk);
                return $received > 65536 ? 0 : strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false) {
                throw new RuntimeException(curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_error');
            }

            return $code;
        } finally {
            curl_close($curl);
        }
    }
}
