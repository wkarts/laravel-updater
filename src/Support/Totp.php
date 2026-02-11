<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class Totp
{
    public function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes($bytes));
    }

    public function verify(string $secret, string $code, int $window = 1, int $step = 30): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timeSlice = (int) floor(time() / $step);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->at($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public function at(string $secret, int $timeSlice): string
    {
        $key = Base32::decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);

        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $truncated % 1_000_000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }
}
