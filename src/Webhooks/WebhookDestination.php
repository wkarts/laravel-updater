<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Webhooks;

use InvalidArgumentException;

class WebhookDestination
{
    /** Resolve again for every delivery; every answer must be public. */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (int) ($parts['port'] ?? 443) !== 443
            || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('Destino deve ser uma URL HTTPS pública na porta 443, sem credenciais.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
                || preg_match('/\.(?:localhost|local|internal|test|invalid|example)$/D', $host)) {
                throw new InvalidArgumentException('Hostname público inválido.');
            }
            $ips = $this->lookup($host);
        }

        if ($ips === []) {
            throw new InvalidArgumentException('Não foi possível resolver o destino público.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new InvalidArgumentException('O destino não pode apontar para redes internas ou endereços reservados.');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => 443, 'ip' => $ips[0]];
    }

    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $blocked = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
            foreach ($blocked as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && self::inCidr($ip, '2000::/3')
            && !self::inCidr($ip, '2001::/23')
            && !self::inCidr($ip, '2001:db8::/32')
            && !self::inCidr($ip, '2002::/16');
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $value = inet_pton($ip);
        $base = inet_pton($network);
        if ($value === false || $base === false || strlen($value) !== strlen($base)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $tail = (int) $bits % 8;
        if (substr($value, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        return $tail === 0 || ((ord($value[$bytes]) & (0xff << (8 - $tail))) === (ord($base[$bytes]) & (0xff << (8 - $tail))));
    }
}
