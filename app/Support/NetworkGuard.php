<?php

namespace App\Support;

use RuntimeException;

/**
 * Decides whether a workflow may call a URL. Private, loopback, link-local
 * and other internal addresses are blocked unless the host or address is on
 * the workspace's internal-address allowlist.
 */
class NetworkGuard
{
    /**
     * Ranges that are internal in addition to what PHP's private and
     * reserved range filters already cover.
     */
    public const EXTRA_INTERNAL_RANGES = [
        '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '64:ff9b::/96',
    ];

    public function __construct(protected HostResolver $resolver) {}

    /**
     * Check a URL and return where to connect: the host, port, and the
     * vetted address to pin the connection to.
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws RuntimeException when the URL is not allowed
     */
    public function check(string $url, AddressAllowlist $allowlist): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException(__('Only http and https URLs can be called.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException(__('Could not resolve :host.', ['host' => $host]));
        }

        foreach ($addresses as $ip) {
            if (static::isInternal($ip) && ! $allowlist->allows($host, $ip)) {
                throw new RuntimeException(__(':host resolves to the internal address :ip, which is not on this workspace\'s internal-address allowlist.', ['host' => $host, 'ip' => $ip]));
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isInternal(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $mapped)) {
            return static::isInternal($mapped[1]);
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        foreach (self::EXTRA_INTERNAL_RANGES as $range) {
            if (AddressAllowlist::ipMatches($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
