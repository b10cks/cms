<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * A token that used up its budget is told when to come back, and still reads
 * the revision: clients fetch it before every render, so a 429 there would
 * also fail the renders whose content the CDN serves.
 */
class DeliveryRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function a_throttled_token_is_told_when_to_retry_and_still_reads_the_revision(): void
    {
        $space = Space::factory()->withLive()->create();
        $token = Token::factory()->create(['space_id' => $space->id, 'expires_at' => null]);
        $this->setUpSpaceTesting($space);
        config(['app.data_api_rate_limit' => 2]);

        $blocks = route('api.blocks.index', ['token' => $token->token]);
        $revision = route('api.spaces.show', ['token' => $token->token]);

        $this->getJson($revision)->assertOk();
        $this->getJson($blocks)->assertOk();
        $this->getJson($blocks)->assertOk()->assertHeader('X-RateLimit-Remaining', 0);

        $throttled = $this->getJson($blocks)->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertHeader('X-RateLimit-Reset');
        $this->assertGreaterThan(0, (int) $throttled->headers->get('Retry-After'));
        $this->assertStringNotContainsString('public', (string) $throttled->headers->get('Cache-Control'));

        $this->getJson($revision)->assertOk()->assertJsonPath('data.rv', $space->rv);
    }
}
