<?php

namespace App\Services\Automation;

use InvalidArgumentException;

class WebhookUrlGuard
{
    /**
     * @return list<string>
     */
    public function assertSafe(string $url, bool $resolveHost = true): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Enter a valid webhook URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '') {
            throw new InvalidArgumentException('The webhook URL needs a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URLs cannot contain credentials.');
        }

        $allowedSchemes = config('automation.webhooks.allow_http', false) ? ['http', 'https'] : ['https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException('Webhook URLs must use HTTPS.');
        }

        if (! $resolveHost) {
            return [];
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolve($host);

        if ($ips === []) {
            throw new InvalidArgumentException('The webhook host could not be resolved.');
        }

        if (! config('automation.webhooks.allow_private_networks', false)) {
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    throw new InvalidArgumentException('Private, loopback, link-local, and reserved webhook hosts are blocked.');
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
