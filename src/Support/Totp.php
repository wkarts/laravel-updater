<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class Totp
{
    public function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes($bytes));
    }

    public function code(string $secret, ?int $time = null, int $period = 30): string
    {
        $time ??= time();
        $counter = (int) floor($time / $period);
        $counterBinary = pack('N*', 0) . pack('N*', $counter);
        $key = Base32::decode($secret);
        $hash = hash_hmac('sha1', $counterBinary, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $slice = substr($hash, $offset, 4);
        $value = unpack('N', $slice)[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function verify(string $secret, string $code, ?int $time = null, int $window = 1, int $period = 30): bool
    {
        $time ??= time();
        $normalized = preg_replace('/\D+/', '', $code) ?? '';

        for ($offset = -$window; $offset <= $window; $offset++) {
            if ($this->code($secret, $time + ($offset * $period), $period) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function otpauthUri(string $issuer, string $email, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&digits=6&period=30&algorithm=SHA1',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }
}
