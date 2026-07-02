<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManagementCorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cors.management_origins' => ['https://app.b10cks.test']]);
    }

    #[Test]
    public function preflight_from_an_allowed_origin_is_granted_credentials(): void
    {
        $response = $this->call('OPTIONS', '/mgmt/v1/spaces', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.b10cks.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertNoContent(204);
        $this->assertSame('https://app.b10cks.test', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function preflight_from_an_unknown_origin_gets_no_cors_headers(): void
    {
        $response = $this->call('OPTIONS', '/mgmt/v1/spaces', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function the_management_api_never_reflects_a_wildcard_with_credentials(): void
    {
        // Wildcard + credentials is the combination that would allow session
        // riding; the public config must not enable credentials.
        $this->assertFalse((bool) config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.management_origins'));
    }
}
