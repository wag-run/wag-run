<?php

namespace App\Support;

/**
 * A workspace's internal-address allowlist: the private, loopback or
 * link-local hosts and ranges its HTTP call nodes may reach.
 *
 * Entries are IP addresses, CIDR ranges (IPv4 or IPv6), hostnames, or
 * wildcard hostnames such as "*.corp.internal".
 */
final readonly class AddressAllowlist
{
    /**
     * @param  list<string>  $entries
     */
    public function __construct(public array $entries) {}

    /**
     * Parse one entry per line or comma, dropping blanks and duplicates.
     *
     * @return list<string>
     */
    public static function parse(string $input): array
    {
        $entries = preg_split('/[\s,]+/', strtolower($input)) ?: [];

        return array_values(array_unique(array_filter($entries, fn (string $entry) => $entry !== '')));
    }

    public static function isValidEntry(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (str_contains($entry, '/')) {
            return self::parseCidr($entry) !== null;
        }

        $host = str_starts_with($entry, '*.') ? substr($entry, 2) : $entry;

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host);
    }

    /**
     * Whether a request to $host, which resolved to $ip, is on the allowlist.
     */
    public function allows(string $host, ?string $ip = null): bool
    {
        $host = strtolower(rtrim($host, '.'));

        foreach ($this->entries as $entry) {
            if (str_starts_with($entry, '*.')) {
                if (str_ends_with($host, substr($entry, 1))) {
                    return true;
                }

                continue;
            }

            if ($entry === $host) {
                return true;
            }

            if ($ip !== null && self::ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function ipMatches(string $ip, string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) && inet_pton($entry) === @inet_pton($ip);
        }

        $cidr = self::parseCidr($entry);
        $address = @inet_pton($ip);

        if ($cidr === null || $address === false || strlen($address) !== strlen($cidr[0])) {
            return false;
        }

        [$network, $bits] = $cidr;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($address, $network, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * @return array{0: string, 1: int}|null packed network address and prefix length
     */
    protected static function parseCidr(string $entry): ?array
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, '');

        if (! filter_var($address, FILTER_VALIDATE_IP) || ! ctype_digit($bits)) {
            return null;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return null;
        }

        $max = strlen($packed) * 8;

        if ((int) $bits > $max) {
            return null;
        }

        return [$packed, (int) $bits];
    }
}
