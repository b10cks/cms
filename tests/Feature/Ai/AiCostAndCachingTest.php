<?php

namespace Tests\Feature\Ai;

use App\Jobs\ReconcileAiRunCostJob;
use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\Run\Transcript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeModelRegistry;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AiCostAndCachingTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private function openRouterRun(): AiRun
    {
        $this->app->instance(ModelRegistry::class, new FakeModelRegistry(new OpenRouterDriver(['api_key' => 'k', 'enabled' => true])));
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        return AiRun::create([
            'space_id' => $space->id,
            'actor_id' => User::factory()->create()->id,
            'surface' => 'cmdk',
            'model' => 'openrouter:openai/gpt-5',
            'state' => 'completed',
            'setup' => ['driver' => 'openrouter', 'generations' => ['gen-1', 'gen-2']],
            'transcript' => [],
            'cost_usd' => 0.5,
        ]);
    }

    #[Test]
    public function the_provider_cost_replaces_the_estimate_and_a_failed_lookup_keeps_it(): void
    {
        $run = $this->openRouterRun();

        Http::fake(['openrouter.ai/api/v1/generation*' => Http::sequence()
            ->push(['data' => ['total_cost' => 0.0125]])
            ->push(['data' => ['total_cost' => 0.0075]])]);
        (new ReconcileAiRunCostJob($run->space_id, $run->id))->handle();
        $this->assertEqualsWithDelta(0.02, $run->refresh()->cost_usd, 1e-9);

        $run->update(['cost_usd' => 0.5]);
        Http::fake(['openrouter.ai/api/v1/generation*' => Http::response(['error' => 'not found'], 404)]);

        $job = (new ReconcileAiRunCostJob($run->space_id, $run->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased();
        $this->assertEqualsWithDelta(0.5, $run->refresh()->cost_usd, 1e-9);
    }

    #[Test]
    public function only_cache_capable_models_get_breakpoints_at_the_cache_breaks(): void
    {
        $driver = new class(['api_key' => 'k']) extends OpenRouterDriver
        {
            /** @return array<int, array<string, mixed>> */
            public function messagesFor(string $model, Transcript $transcript): array
            {
                return $this->openAiMessages($transcript, $this->usesCacheBreakpoints($model));
            }
        };
        $transcript = (new Transcript)->appendSystem("Styles\n\nCatalog\n\nTask", [6, 15])->appendUser('Hi');

        $parts = $driver->messagesFor('anthropic/claude-sonnet-4.5', $transcript)[0]['content'];
        $this->assertSame(['Styles', "\n\nCatalog", "\n\nTask"], array_column($parts, 'text'));
        $this->assertSame(['ephemeral'], [$parts[0]['cache_control']['type']]);
        $this->assertArrayHasKey('cache_control', $parts[1]);
        $this->assertArrayNotHasKey('cache_control', $parts[2]);
        $this->assertIsArray($driver->messagesFor('google/gemini-2.5-pro', $transcript)[0]['content']);

        $this->assertSame("Styles\n\nCatalog\n\nTask", $driver->messagesFor('openai/gpt-5', $transcript)[0]['content']);
    }
}
