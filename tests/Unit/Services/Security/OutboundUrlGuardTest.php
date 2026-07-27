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
        ];
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
