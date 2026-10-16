<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class Totp
{
    public function qrcodeDataUri(string $otpauthUri, int $size = 220): string
    {
        // The enrollment URI contains the TOTP secret. Never send it to a
        // remote QR service. Vue renders the real QR locally from otpauthUri.
        // Keep a clearly labelled local fallback for legacy consumers.
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d"><rect width="100%%" height="100%%" fill="#f3f4f6"/><text x="50%%" y="45%%" text-anchor="middle" font-size="14" fill="#111827" font-family="Arial">QRCode indisponível</text><text x="50%%" y="58%%" text-anchor="middle" font-size="10" fill="#4b5563" font-family="Arial">Copie o segredo manualmente</text></svg>',
            $size
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

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
