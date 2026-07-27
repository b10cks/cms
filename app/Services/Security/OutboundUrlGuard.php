<?php

namespace App\Services\Security;

use App\Services\Security\Exceptions\UnsafeUrlException;

/**
 * Validates that a user-supplied URL is safe to request server-side, guarding
 * against SSRF: only http/https is allowed, and the host must not resolve to a
 * private, loopback, link-local or otherwise reserved address (which would let
 * an operator reach internal services or the cloud metadata endpoint).
 *
 * Checking the address is only half the job. The HTTP client resolves the name
 * a second time when it connects, and an attacker controlling the zone can
 * answer differently that second time — the guard sees a public address, curl
 * gets 169.254.169.254. `assertSafe()` therefore returns the addresses it
 * approved, and callers must pin the connection to them with
 * `curlResolveFor()`. Callers must also disable redirect following, since a
 * 30x to an internal host is checked by nobody.
 */
class OutboundUrlGuard
{
    /**
     * Address families and ranges PHP's own filter does not consider private
     * or reserved, but which are routable only inside someone's network.
     *
     * 100.64.0.0/10 is carrier-grade NAT and is where EKS and GKE put pods and
     * nodes — Alibaba's metadata endpoint lives at 100.100.100.200. The rest
     * are IETF special-purpose ranges that have no business being the target
     * of an outbound webhook.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const ADDITIONAL_DENIED_RANGES = [
        ['100.64.0.0', 10],   // Carrier-grade NAT / cloud internal
        ['192.0.0.0', 24],    // IETF protocol assignments
        ['192.0.2.0', 24],    // TEST-NET-1
        ['198.18.0.0', 15],   // Benchmarking
        ['198.51.100.0', 24], // TEST-NET-2
        ['203.0.113.0', 24],  // TEST-NET-3
        ['240.0.0.0', 4],     // Reserved, includes 255.255.255.255
    ];

    /**
     * The addresses the URL's host resolved to, all of them verified public.
     *
     * @return array<int, string>
     *
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new UnsafeUrlException('The webhook URL is not a valid URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('The webhook URL must use http or https.');
        }

        $host = $parts['host'];
        $addresses = $this->resolve($host);

        if ($addresses === []) {
            throw new UnsafeUrlException("The webhook host \"{$host}\" could not be resolved.");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new UnsafeUrlException('The webhook URL resolves to a non-routable or internal address.');
            }
        }

        return $addresses;
    }

    /**
     * CURLOPT_RESOLVE entries pinning the URL's host to the addresses that
     * were just approved, so the client cannot resolve it a second time and
     * get a different answer.
     *
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    public function curlResolveFor(string $url, array $addresses): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if ($host === null || $addresses === []) {
            return [];
        }

        // An IP literal has nothing to resolve, so there is nothing to pin.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);

        return [$host.':'.$port.':'.implode(',', $addresses)];
    }

    /**
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        // Host is already an IP literal (strip IPv6 brackets first).
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        // Fallback for environments where dns_get_record is limited.
        if ($addresses === []) {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                $addresses = $resolved;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $ip): bool
    {
        $ip = $this->unwrapIpv4($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 6to4 embeds an IPv4 address that the filter above never looks at.
        if (str_starts_with(strtolower($ip), '2002:')) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return true;
        }

        foreach (self::ADDITIONAL_DENIED_RANGES as [$network, $bits]) {
            if ($this->inIpv4Range($ip, $network, $bits)) {
                return false;
            }
        }

        return true;
    }

    private function inIpv4Range(string $ip, string $network, int $bits): bool
    {
        $address = ip2long($ip);
        $subnet = ip2long($network);

        if ($address === false || $subnet === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return ($address & $mask) === ($subnet & $mask);
    }

    /**
     * Reduce an IPv6 address that merely wraps an IPv4 one to that IPv4
     * address, so the IPv4 range rules decide the outcome.
     *
     * PHP 8.5's FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE already reject the
     * wrapped forms of internal addresses, so this is not closing a live hole.
     * It is here because that behaviour has not been consistent across PHP
     * versions and the guard should not depend on it: an IPv4-mapped
     * `::ffff:169.254.169.254` must never reach the metadata endpoint.
     */
    private function unwrapIpv4(string $ip): string
    {
        $normalized = strtolower(trim($ip, '[]'));

        foreach (['::ffff:', '::'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $candidate = substr($normalized, strlen($prefix));

                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $candidate;
                }
            }
        }

        // NAT64 (64:ff9b::/96) embeds the IPv4 address in the low 32 bits.
        if (str_starts_with($normalized, '64:ff9b::')) {
            $packed = @inet_pton($normalized);

            if ($packed !== false && strlen($packed) === 16) {
                return inet_ntop(substr($packed, 12, 4));
            }
        }

        return $ip;
    }
}
