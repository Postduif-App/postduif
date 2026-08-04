<?php

namespace App\Workflows;

use RuntimeException;

/**
 * Decide whether this server is willing to go and fetch something.
 *
 * The HTTP step lets a workspace beheerder choose an address that this server
 * then requests. That is a useful thing and a dangerous one: a beheerder
 * administers a workspace, not a network, and without a check here the step is
 * a way to read the cloud metadata endpoint, reach internal admin panels that
 * are only protected by not being on the internet, and map the private network
 * from a machine that is allowed to see it.
 *
 * So the rule is the other way round from most: an address is refused unless it
 * is plainly outside. What that costs is a workflow that cannot call something
 * on the same machine — which is why there is a setting for a development
 * machine, and why it is off by default.
 *
 * The residual risk, said out loud: the name is resolved here and resolved
 * again by the client that connects, so a name that answers with a public
 * address now and a private one a moment later would slip through. Closing that
 * means connecting to the address we checked and carrying the name along
 * ourselves, which breaks certificate checking unless it is done carefully.
 * Redirects are refused for the same family of reasons, and that one is cheap
 * enough to simply do.
 */
class GuardOutboundUrl
{
    /**
     * The ranges that are somebody's inside rather than the internet.
     *
     * Written out rather than left to a "is this public" helper, because the
     * one that matters most is not a private range at all: 169.254.169.254 is
     * where every cloud hands out credentials to whoever asks from the machine.
     *
     * @var list<string>
     */
    private const CLOSED = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * Hand back the address, or say why not.
     *
     * @throws RuntimeException when the address is not one we will fetch
     */
    public function handle(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new RuntimeException(__('workflows.errors.url_unreadable'));
        }

        /*
         * http and https and nothing else, and asked before anything else is.
         * The schemes that are not a request over a network at all — file://,
         * and whatever else a client has been talked into supporting over the
         * years — mostly have no host either, and "dat is geen adres" is not
         * the thing to tell somebody who typed file:///etc/passwd.
         */
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException(__('workflows.errors.url_scheme'));
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            throw new RuntimeException(__('workflows.errors.url_unreadable'));
        }

        if (config('workflows.http.allow_private_hosts') === true) {
            return $url;
        }

        foreach ($this->addressesOf($parts['host']) as $address) {
            if ($this->isClosed($address)) {
                throw new RuntimeException(__('workflows.errors.url_not_public'));
            }
        }

        return $url;
    }

    /**
     * Every address the name answers with.
     *
     * All of them, not the first: a name that resolves to one public address
     * and one loopback address is a name that reaches loopback often enough,
     * and which of the two a client picks is not ours to predict.
     *
     * @return list<string>
     */
    private function addressesOf(string $host): array
    {
        // Already an address rather than a name — including the bracketed form
        // a URL writes IPv6 in.
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || $records === []) {
            /*
             * A name nothing answers for. Refused here rather than left to the
             * request, so that the run screen says something a person can act
             * on instead of a client's own wording for the same thing.
             */
            throw new RuntimeException(__('workflows.errors.url_unknown_host'));
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }

    private function isClosed(string $address): bool
    {
        if (str_contains($address, ':')) {
            /*
             * IPv6, where the reserved-range arithmetic is a great deal of code
             * for very little: what is left after the filter below is a global
             * unicast address, and everything else — loopback, link-local,
             * unique-local — is exactly what we are refusing.
             */
            return filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false;
        }

        foreach (self::CLOSED as $range) {
            if ($this->within($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private function within(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $mask = -1 << (32 - (int) $bits);

        return (ip2long($address) & $mask) === (ip2long($subnet) & $mask);
    }
}
