<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class Totp
{
    public function qrcodeDataUri(string $otpauthUri, int $size = 220): string
    {
        $png = $this->fetchQrPng($otpauthUri, $size);
        if ($png !== null) {
            return 'data:image/png;base64,' . base64_encode($png);
        }

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

    private function fetchQrPng(string $otpauthUri, int $size): ?string
    {
        $providers = [
            'https://quickchart.io/qr?size=%1$dx%1$d&text=%2$s',
            'https://chart.googleapis.com/chart?chs=%1$dx%1$d&cht=qr&chl=%2$s',
        ];

        foreach ($providers as $template) {
            $url = sprintf($template, $size, rawurlencode($otpauthUri));

            $png = $this->fetchViaCurl($url);
            if ($png === null) {
                $png = $this->fetchViaFileGetContents($url);
            }

            if ($png !== null && $png !== '') {
                return $png;
            }
        }

        return null;
    }

    private function fetchViaCurl(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($data) || $code < 200 || $code >= 300) {
            return null;
        }

        return $data;
    }

    private function fetchViaFileGetContents(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);

        return is_string($data) && $data !== '' ? $data : null;
    }
}
