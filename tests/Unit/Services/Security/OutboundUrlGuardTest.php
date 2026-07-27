<?php

namespace Tests\Unit\Services\Security;

use App\Services\Security\Exceptions\UnsafeUrlException;
use App\Services\Security\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OutboundUrlGuardTest extends TestCase
{
    #[Test]
    #[DataProvider('internalUrls')]
    public function it_rejects_addresses_that_are_not_publicly_routable(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);

        new OutboundUrlGuard()->assertSafe($url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function internalUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'private range' => ['http://10.0.0.5/'],
            'link local' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
            // The IPv4 rules do not apply to these forms unless the address is
            // unwrapped first, so each one used to sail past the guard.
            'ipv4 mapped ipv6 metadata' => ['http://[::ffff:169.254.169.254]/latest/meta-data/'],
            'ipv4 mapped ipv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'ipv4 compatible ipv6' => ['http://[::10.0.0.5]/'],
            'nat64 metadata' => ['http://[64:ff9b::169.254.169.254]/'],
            // PHP's own filter calls these public. They are routable only
            // inside somebody's network.
            'carrier grade nat' => ['http://100.64.0.1/'],
            'alibaba metadata' => ['http://100.100.100.200/latest/meta-data/'],
            'ietf protocol assignments' => ['http://192.0.0.1/'],
            'benchmarking range' => ['http://198.18.0.1/'],
            'reserved class e' => ['http://240.0.0.1/'],
            '6to4' => ['http://[2002:a00:1::1]/'],
        ];
    }

    #[Test]
    public function it_returns_the_addresses_it_approved(): void
    {
        $addresses = new OutboundUrlGuard()->assertSafe('https://93.184.216.34/hook');

        $this->assertSame(['93.184.216.34'], $addresses);
    }

    /**
     * The client resolves the name again when it connects, and a zone the
     * attacker controls can answer differently that second time. Pinning the
     * connection to the approved addresses is what closes that window.
     */
    #[Test]
    public function it_pins_the_connection_to_the_approved_addresses(): void
    {
        $guard = new OutboundUrlGuard();

        $this->assertSame(
            ['example.test:443:93.184.216.34,93.184.216.35'],
            $guard->curlResolveFor('https://example.test/hook', ['93.184.216.34', '93.184.216.35']),
        );

        $this->assertSame(
            ['example.test:8080:93.184.216.34'],
            $guard->curlResolveFor('http://example.test:8080/hook', ['93.184.216.34']),
        );

        $this->assertSame(
            ['example.test:80:93.184.216.34'],
            $guard->curlResolveFor('http://example.test/hook', ['93.184.216.34']),
        );
    }

    #[Test]
    public function an_ip_literal_needs_no_pinning(): void
    {
        $guard = new OutboundUrlGuard();

        $this->assertSame([], $guard->curlResolveFor('https://93.184.216.34/hook', ['93.184.216.34']));
    }

    #[Test]
    #[DataProvider('rejectedSchemes')]
    public function it_only_allows_http_and_https(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);

        new OutboundUrlGuard()->assertSafe($url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedSchemes(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://127.0.0.1/'],
            'no scheme' => ['not-a-url'],
        ];
    }
}
