<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthTest extends TestCase
{
    #[Test]
    public function it_returns_data()
    {
        $this->getJson('mgmt/v1/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'version' => config('app.version'),
                'timestamp' => now()->toIso8601String(),
            ]);
    }
}
