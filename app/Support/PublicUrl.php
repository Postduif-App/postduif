<?php

namespace App\Support;

use App\Support\Dns\HostResolver;

/**
 * Whether a URL is safe for the server to open on somebody else's behalf.
 *
 * This is the whole security surface of link previews. Without it the feature
 * is an SSRF endpoint with a chat around it: anybody who can type a message
 * could make the server fetch http://169.254.169.254/ — the cloud metadata
 * address — or reach into a private network the browser never could.
 *
 * Three rules, and all three are needed:
 *
 * 1. Only http and https. A file:// or gopher:// URL is not a web page, and
 *    curl is happy to open several of them.
 * 2. The host must resolve to a public address. Checking the hostname against
 *    a blocklist is not enough — "localtest.me" resolves to 127.0.0.1, and so
 *    does anything an attacker controls a DNS record for.
 * 3. Every address the name resolves to has to pass, not just the first. A
 *    name with an A record in public space and another in private space would
 *    otherwise be a coin flip.
 *
 * Note what this cannot do on its own: a redirect goes to a new URL, so the
 * caller has to ask again at every hop. See FetchLinkPreview.
 */
class PublicUrl
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * The ranges nothing on the public internet lives in.
     *
     * Spelled out rather than left to FILTER_FLAG_NO_PRIV_RANGE, which misses
     * the link-local range that carries cloud metadata — the single most
     * valuable thing an SSRF can reach.
     *
     * @var array<int, string>
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',        // "this network"
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, including cloud metadata
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // protocol assignments
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
    ];

    /**
     * Why this URL may not be opened, or null when it may.
     *
     * A reason rather than a boolean, because it is written to the row: a
     * preview that will never work should say so once instead of being
     * attempted again on every message that mentions the link.
     */
    public function refuse(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Geen geldige URL.';
        }

        if (! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Alleen http en https.';
        }

        // parse_url keeps the brackets on an IPv6 literal, and the filter below
        // does not take them — so "[::1]" would fall through to the resolver
        // and be treated as a name.
        $host = trim($parts['host'], '[]');

        /*
         * A host with no letters in it is somebody writing an address, not a
         * name — and "127.1", "2130706433" and "0x7f.1" are all addresses that
         * curl resolves to loopback while filter_var() calls them invalid.
         *
         * Refused outright rather than normalised: there is no shorthand form
         * anybody types on purpose in a chat message, and guessing at every
         * variant is a game with no last move.
         */
        if (preg_match('/^[0-9a-fx.:]+$/i', $host) === 1
            && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return 'Geen geldige URL.';
        }

        // A bare address skips DNS entirely, and skipping the resolve step is
        // exactly what somebody writing http://127.0.0.1/ is counting on.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolver->resolve($host);

        if ($addresses === []) {
            return 'Deze naam is niet te vinden.';
        }

        foreach ($addresses as $address) {
            if (self::isPrivate($address)) {
                return 'Dit adres ligt binnen ons eigen netwerk.';
            }
        }

        return null;
    }

    private static function isPrivate(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ::1 is loopback; fc00::/7 is unique-local; fe80::/10 is
            // link-local. Compared on the packed form so shorthand notations
            // cannot slip past a string comparison.
            $packed = inet_pton($address);

            if ($packed === false) {
                return true;
            }

            if ($packed === inet_pton('::1')) {
                return true;
            }

            $first = ord($packed[0]);

            return ($first & 0xFE) === 0xFC
                || ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $ip = ip2long($address);
        $net = ip2long($subnet);

        if ($ip === false || $net === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ip & $mask) === ($net & $mask);
    }
}
