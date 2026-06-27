<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiKey;
use App\Services\Ai\AiKeySpec;
use App\Services\Ai\OpenRouterKeyManager;
use App\Services\Ai\PlanAiKeyResolver;
use App\Services\Ai\SpaceAiUsageService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceAiUsageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('ai.drivers.openrouter.enabled', true);
        config()->set('ai.default', 'openrouter');
    }

    /**
     * Build a Space whose default-provider resolves to openrouter and whose
     * aiKeys() relation chain returns the given key (or null).
     */
    private function spaceWithKey(?SpaceAiKey $key): Space
    {
        $space = Mockery::mock(Space::class)->makePartial();
        // No default config => provider falls back to config('ai.default').
        $space->setRelation('defaultAiConfig', null);

        $builder = Mockery::mock(HasMany::class);
        $builder->shouldReceive('forDriver')->with('openrouter')->andReturnSelf();
        $builder->shouldReceive('active')->andReturnSelf();
        $builder->shouldReceive('latest')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($key);

        $space->shouldReceive('aiKeys')->andReturn($builder);

        return $space;
    }

    private function service(PlanAiKeyResolver $resolver, OpenRouterKeyManager $keys): SpaceAiUsageService
    {
        return new SpaceAiUsageService($resolver, $keys);
    }

    private function makeKey(array $attributes): SpaceAiKey
    {
        return (new SpaceAiKey())->forceFill($attributes);
    }

    #[Test]
    public function it_maps_a_live_openrouter_usage_snapshot(): void
    {
        $key = $this->makeKey([
            'id' => 'key_1',
            'driver' => 'openrouter',
            'external_key_hash' => 'hash_1',
            'limit' => 5.0,
            'limit_reset' => 'monthly',
        ]);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(
            new AiKeySpec(eligible: true, limit: 5.0, limitReset: 'monthly')
        );

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldReceive('getKeyUsage')->once()->andReturn([
            'usage' => 2.5,
            'limit' => 5.0,
            'limit_remaining' => 2.5,
            'limit_reset' => 'monthly',
            'usage_daily' => 0.4,
            'usage_weekly' => 1.1,
            'usage_monthly' => 2.5,
        ]);

        $dto = $this->service($resolver, $keys)->forSpace($this->spaceWithKey($key));

        $this->assertTrue($dto->available);
        $this->assertTrue($dto->live);
        $this->assertFalse($dto->unlimited);
        $this->assertSame('usd', $dto->unit);
        $this->assertSame(2.5, $dto->used);
        $this->assertSame(5.0, $dto->limit);
        $this->assertSame(2.5, $dto->remaining);
        $this->assertSame(50, $dto->percentage());
        $this->assertSame(0.4, $dto->breakdown['daily']);
        $this->assertNotNull($dto->resetsAt);
    }

    #[Test]
    public function a_null_live_limit_is_reported_as_unlimited(): void
    {
        $key = $this->makeKey(['id' => 'key_1', 'driver' => 'openrouter', 'external_key_hash' => 'h']);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(new AiKeySpec(eligible: true, unlimited: true));

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldReceive('getKeyUsage')->andReturn([
            'usage' => 7.0,
            'limit' => null,
            'limit_remaining' => null,
        ]);

        $dto = $this->service($resolver, $keys)->forSpace($this->spaceWithKey($key));

        $this->assertTrue($dto->available);
        $this->assertTrue($dto->live);
        $this->assertTrue($dto->unlimited);
        $this->assertSame(7.0, $dto->used);
        $this->assertNull($dto->limit);
        $this->assertNull($dto->resetsAt);
    }

    #[Test]
    public function an_ineligible_space_reports_unavailable(): void
    {
        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(AiKeySpec::ineligible());

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldNotReceive('getKeyUsage');

        $space = Mockery::mock(Space::class)->makePartial();
        $space->setRelation('defaultAiConfig', null);

        $dto = $this->service($resolver, $keys)->forSpace($space);

        $this->assertFalse($dto->available);
        $this->assertNotNull($dto->message);
    }

    #[Test]
    public function an_eligible_space_without_a_key_reports_its_plan_allowance(): void
    {
        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(
            new AiKeySpec(eligible: true, limit: 5.0, limitReset: 'monthly')
        );

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldNotReceive('getKeyUsage');

        $dto = $this->service($resolver, $keys)->forSpace($this->spaceWithKey(null));

        $this->assertTrue($dto->available);
        $this->assertFalse($dto->live);
        $this->assertSame(0.0, $dto->used);
        $this->assertSame(5.0, $dto->limit);
        $this->assertSame(5.0, $dto->remaining);
    }

    #[Test]
    public function a_failed_live_fetch_falls_back_to_the_local_limit(): void
    {
        $key = $this->makeKey([
            'id' => 'key_1',
            'driver' => 'openrouter',
            'external_key_hash' => 'hash_1',
            'limit' => 5.0,
            'limit_reset' => 'monthly',
        ]);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(
            new AiKeySpec(eligible: true, limit: 5.0, limitReset: 'monthly')
        );

        $keys = Mockery::mock(OpenRouterKeyManager::class);
        $keys->shouldReceive('getKeyUsage')->andThrow(new \RuntimeException('boom'));

        $dto = $this->service($resolver, $keys)->forSpace($this->spaceWithKey($key));

        $this->assertTrue($dto->available);
        $this->assertFalse($dto->live);
        $this->assertSame(5.0, $dto->limit);
        $this->assertNotNull($dto->message);
    }

    #[Test]
    public function a_disabled_openrouter_driver_reports_unavailable(): void
    {
        config()->set('ai.drivers.openrouter.enabled', false);

        $resolver = Mockery::mock(PlanAiKeyResolver::class);
        $keys = Mockery::mock(OpenRouterKeyManager::class);

        $space = Mockery::mock(Space::class)->makePartial();
        $space->setRelation('defaultAiConfig', null);

        $dto = $this->service($resolver, $keys)->forSpace($space);

        $this->assertFalse($dto->available);
    }
}
