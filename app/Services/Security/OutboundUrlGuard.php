<?php

namespace App\Services\Security;

use App\Services\Security\Exceptions\UnsafeUrlException;

/**
 * Validates that a user-supplied URL is safe to request server-side, guarding
 * against SSRF: only http/https is allowed, and the host must not resolve to a
 * private, loopback, link-local or otherwise reserved address (which would let
 * an operator reach internal services or the cloud metadata endpoint).
 *
 * Note: this validates the addresses the host resolves to at check time. It
 * does not by itself defeat DNS-rebinding — callers should also disable HTTP
 * redirect following (an attacker could otherwise redirect to an internal
 * host after the check).
 */
class OutboundUrlGuard
{
    /**
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): void
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

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
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
