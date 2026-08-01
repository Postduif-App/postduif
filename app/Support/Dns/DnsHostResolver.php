<?php

namespace App\Support\Dns;

/**
 * The real thing: what the system resolver says.
 */
class DnsHostResolver implements HostResolver
{
    /**
     * @return array<int, string>
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
