<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Webhooks;

final class WebhookSignature
{
    public static function sign(string $secret, string $timestamp, string $eventId, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $body, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $eventId, string $body, string $signature, ?int $now = null): bool
    {
        if (!preg_match('/^\d{10}$/D', $timestamp)
            || abs(($now ?? time()) - (int) $timestamp) > 300
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $eventId)
            || !preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $eventId, $body), $signature);
    }
}
