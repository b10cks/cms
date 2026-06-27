<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiKey;
use App\Services\Ai\AiKeySpec;
use App\Services\Ai\OpenRouterKeyManager;
use App\Services\Ai\PlanAiKeyResolver;
use App\Services\Ai\SpaceAiKeyProvisioner;
use App\Services\Ai\SpaceAiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiSpendCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.drivers.openrouter.enabled', true);
        config()->set('ai.drivers.openrouter.management_key', 'test-management-key');
    }

    private function key(Space $space, array $overrides = []): SpaceAiKey
    {
        return SpaceAiKey::create(array_merge([
            'space_id' => $space->id,
            'driver' => 'openrouter',
            'key_hash' => 'hash-'.uniqid(),
            'encrypted_key' => 'enc',
            'name' => 'b10cks-key',
            'external_key_hash' => 'ext-'.uniqid(),
            'limit' => 5.0,
            'limit_reset' => 'monthly',
        ], $overrides));
    }

    #[Test]
    public function it_captures_final_spend_before_revoking_a_key(): void
    {
        $space = Space::factory()->create();
        $key = $this->key($space);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(AiKeySpec::ineligible());

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        // Usage must be fetched *before* the key is revoked.
        $keys->shouldReceive('getKeyUsage')->once()->ordered()->andReturn(['usage' => 3.5]);
        $keys->shouldReceive('revokeKey')->once()->ordered();

        (new SpaceAiKeyProvisioner($resolver, $keys))->syncForSpace($space);

        $key->refresh();
        $this->assertSame(3.5, (float) $key->final_usage_usd);
        $this->assertNotNull($key->usage_captured_at);
    }

    #[Test]
    public function it_does_not_recapture_a_key_whose_usage_was_already_recorded(): void
    {
        $space = Space::factory()->create();
        $key = $this->key($space, ['final_usage_usd' => 4.0, 'usage_captured_at' => now()->subDay()]);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(AiKeySpec::ineligible());

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldNotReceive('getKeyUsage');
        $keys->shouldReceive('revokeKey')->once();

        (new SpaceAiKeyProvisioner($resolver, $keys))->syncForSpace($space);

        $key->refresh();
        $this->assertSame(4.0, (float) $key->final_usage_usd);
    }

    #[Test]
    public function spend_for_window_sums_captured_and_live_key_usage(): void
    {
        $space = Space::factory()->create();
        // Captured key: uses its stored final spend, no live fetch.
        $this->key($space, ['final_usage_usd' => 2.0, 'usage_captured_at' => now()->subDay(), 'disabled_at' => now()->subHour()]);
        // Active key: spend fetched live.
        $this->key($space);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldReceive('getKeyUsage')->once()->andReturn(['usage' => 1.5]);

        $service = new SpaceAiUsageService($resolver, $keys);
        $total = $service->spendForWindow($space, now()->subDays(10), now());

        $this->assertSame(3.5, $total);
    }
}
