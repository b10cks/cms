<?php

namespace Tests\Feature\Ai;

use App\Enums\AiOperationKind;
use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Jobs\ReconcileAiRunCostJob;
use App\Models\Management\Space;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Run\Transcript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeModelRegistry;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AiCostAndCachingTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    /** @var array<string, float> */
    private array $generationCosts = [];

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
    public function a_confirmed_cost_moves_the_operation_spend_by_the_difference_up_or_down_and_once(): void
    {
        $run = $this->openRouterRun();
        $operation = $this->operationOf($run);
        $other = AiRun::create([...$run->only(['space_id', 'actor_id', 'surface', 'model', 'state', 'setup', 'transcript']), 'operation_id' => $operation->id, 'cost_usd' => 0.25]);

        $operations = app(AiOperations::class);
        $operation->forceFill(['current_run_id' => $other->id])->save();
        $operations->runFinished($other);
        $operation->forceFill(['current_run_id' => $run->id])->save();
        $operations->runFinished($run);
        $this->assertEqualsWithDelta(0.75, $operation->refresh()->spent_usd, 1e-6);
        $this->assertNull($run->refresh()->cost_confirmed_at);

        $this->reconcile($run, ['gen-1' => 0.0125, 'gen-2' => 0.0075]);
        $this->assertEqualsWithDelta(0.27, $operation->refresh()->spent_usd, 1e-6);
        $this->assertNotNull($run->refresh()->cost_confirmed_at);

        $this->reconcile($run, ['gen-1' => 0.0125, 'gen-2' => 0.0075]);
        $this->assertEqualsWithDelta(0.27, $operation->refresh()->spent_usd, 1e-6);

        $this->reconcile($run, ['gen-1' => 0.6, 'gen-2' => 0.3]);
        $this->assertEqualsWithDelta(1.15, $operation->refresh()->spent_usd, 1e-6);
        $this->assertEqualsWithDelta(0.9, $run->refresh()->cost_usd, 1e-6);
        $this->assertEqualsWithDelta(0.25, $other->refresh()->counted_cost_usd, 1e-6);
    }

    #[Test]
    public function a_cost_confirmed_before_settling_is_counted_once(): void
    {
        $run = $this->openRouterRun();
        $run->update(['input_tokens' => 7]);
        $operation = $this->operationOf($run);
        $operation->forceFill(['current_run_id' => $run->id])->save();

        $this->reconcile($run, ['gen-1' => 0.0125, 'gen-2' => 0.0075]);
        $this->assertEqualsWithDelta(0.02, $operation->refresh()->spent_usd, 1e-6);

        app(AiOperations::class)->runFinished($run->refresh());
        app(AiOperations::class)->runFinished($run->refresh());

        $operation->refresh();
        $this->assertEqualsWithDelta(0.02, $operation->spent_usd, 1e-6);
        $this->assertSame(7, $operation->input_tokens);
        $this->assertNull($operation->current_run_id);
    }

    #[Test]
    public function a_stale_copy_of_the_run_cannot_count_its_cost_twice(): void
    {
        $run = $this->openRouterRun();
        $operation = $this->operationOf($run);
        $stale = AiRun::query()->findOrFail($run->id);

        app(AiOperations::class)->syncSpend($run);
        $run->update(['cost_usd' => 0.2]);
        app(AiOperations::class)->syncSpend($stale);
        app(AiOperations::class)->syncSpend($run);

        $this->assertEqualsWithDelta(0.2, $operation->refresh()->spent_usd, 1e-6);
    }

    #[Test]
    public function a_planning_run_stays_outside_the_approved_budget_and_a_lower_cost_extends_nothing(): void
    {
        $run = $this->openRouterRun();
        $operation = $this->operationOf($run);
        $operation->forceFill(['plan_run_id' => $run->id])->save();
        $this->reconcile($run, ['gen-1' => 0.1, 'gen-2' => 0.1]);
        $this->assertEqualsWithDelta(0.0, $operation->refresh()->spent_usd, 1e-6);

        $batch = AiRun::create([...$run->only(['space_id', 'actor_id', 'surface', 'model', 'state', 'setup', 'transcript']), 'operation_id' => $operation->id, 'cost_usd' => 1.0]);
        $operation->forceFill(['current_run_id' => $batch->id, 'pause_reason' => AiOperationPauseReason::Budget])->save();
        app(AiOperations::class)->runFinished($batch);
        $this->assertSame(0.0, $operation->refresh()->remainingUsd());

        $this->reconcile($batch, ['gen-1' => 0.1, 'gen-2' => 0.1]);

        $operation->refresh();
        $this->assertEqualsWithDelta(0.2, $operation->spent_usd, 1e-6);
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame(AiOperationPauseReason::Budget, $operation->pause_reason);
        $this->assertEqualsWithDelta(1.0, $operation->max_usd, 1e-6);
    }

    /** @param  array{gen-1: float, gen-2: float}  $costs */
    private function reconcile(AiRun $run, array $costs): void
    {
        $this->generationCosts = $costs;
        Http::fake(['openrouter.ai/api/v1/generation*' => fn (Request $request) => Http::response(['data' => ['total_cost' => $this->generationCosts[$request['id']]]])]);
        (new ReconcileAiRunCostJob($run->space_id, $run->id))->handle();
    }

    /** A paused operation: settling moves its numbers without starting more work. */
    private function operationOf(AiRun $run): AiOperation
    {
        $operation = AiOperation::create([
            'space_id' => $run->space_id,
            'actor_id' => $run->actor_id,
            'kind' => AiOperationKind::General,
            'surface' => $run->surface,
            'prompt' => 'Rewrite.',
            'scope' => ['type' => 'content'],
            'counts' => [],
            'state' => AiOperationState::Paused,
            'max_usd' => 1.0,
        ]);
        $run->update(['operation_id' => $operation->id]);

        return $operation;
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
