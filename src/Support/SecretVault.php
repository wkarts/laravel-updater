<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

/** Encrypts stored credentials with the host application's configured key. */
final class SecretVault
{
    private const PREFIX = 'updater:v1:';

    public static function encrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function seal(?string $value): ?string
    {
        if ($value === null || $value === '' || self::encrypted($value)) {
            return $value;
        }

        return self::PREFIX . self::encrypter()->encrypt($value, false);
    }

    public static function open(?string $value): ?string
    {
        if (!self::encrypted($value)) {
            return $value;
        }

        try {
            return (string) self::encrypter()->decrypt(substr((string) $value, strlen(self::PREFIX)), false);
        } catch (\Throwable $exception) {
            // Do not disclose the ciphertext, key or provider exception in UI/logs.
            throw new RuntimeException('Não foi possível ler a credencial protegida. Confira a chave da aplicação e restaure a configuração.', 0);
        }
    }

    /** Protect only keys carrying credentials, preserving runtime payload shapes. */
    public static function sealFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sealFields($value);
            } elseif (is_string($value) && self::sensitive((string) $key)) {
                $data[$key] = self::seal($value);
            }
        }

        return $data;
    }

    public static function openFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::openFields($value);
            } elseif (is_string($value) && self::encrypted($value)) {
                $data[$key] = self::open($value);
            }
        }

        return $data;
    }

    public static function sensitive(string $key): bool
    {
        return preg_match('/(?:password|token|secret|private_key|access_key|sync_key|app\.key)/i', $key) === 1;
    }

    public static function publicFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::publicFields($value);
            } elseif (self::sensitive((string) $key)) {
                unset($data[$key]);
                $data[$key . '_configured'] = $value !== null && $value !== '';
            } elseif (is_string($value)) {
                $data[$key] = RunDiagnosticsService::sanitize($value);
            }
        }

        return $data;
    }

    private static function encrypter(): Encrypter
    {
        try {
            $encrypter = app('encrypter');
        } catch (\Throwable) {
            throw new RuntimeException('Configure uma chave válida na aplicação antes de armazenar credenciais.');
        }
        if (!$encrypter instanceof Encrypter) {
            throw new RuntimeException('Configure uma chave válida na aplicação antes de armazenar credenciais.');
        }

        return $encrypter;
    }
}
