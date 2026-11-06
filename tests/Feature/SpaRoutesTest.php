<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpaRoutesTest extends TestCase
{
    /** Vue routes that also need a Laravel route, or reloading them gives a bare 404. */
    #[DataProvider('paths')]
    public function test_deep_links_reach_the_app(string $path): void
    {
        $this->assertNotSame(404, $this->get($path)->getStatusCode());
    }

    public static function paths(): array
    {
        return array_map(fn (string $path) => [$path], [
            '/teams/01TEAM/overview',
            '/teams/01TEAM/spaces',
            '/teams/01TEAM/activity',
            '/provider/tenants',
            '/provider/users',
            '/provider/usage',
            '/provider/audit',
            '/provider/health',
        ]);
    }
}
