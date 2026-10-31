<?php

namespace Tests\Unit\Services\Ai;

use App\Enums\AiReasoningEffort;
use App\Services\Ai\Drivers\BaseAiDriver;
use App\Services\Ai\Drivers\OpenAiDriver;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\ModelRegistry;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ReasoningEffortShapingTest extends TestCase
{
    #[Test]
    public function openrouter_sends_the_effort_or_switches_reasoning_off(): void
    {
        $driver = new class(['api_key' => 'test-key']) extends OpenRouterDriver
        {
            protected function fetchModels(): array
            {
                return [];
            }
        };

        $this->assertSame(['effort' => 'high'], $this->params($driver, 'high')['reasoning']);
        $this->assertSame(['enabled' => false], $this->params($driver, 'off')['reasoning']);
        $this->assertArrayNotHasKey('reasoning', $this->params($driver, null));
    }

    #[Test]
    public function openai_sends_reasoning_effort_and_leaves_it_out_for_off(): void
    {
        $driver = new class(['api_key' => 'test-key']) extends OpenAiDriver
        {
            protected function fetchModels(): array
            {
                return [];
            }
        };

        $this->assertSame('low', $this->params($driver, 'low')['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_effort', $this->params($driver, 'off'));
    }

    #[Test]
    public function a_model_that_must_reason_gets_low_for_off_and_one_that_cannot_gets_nothing(): void
    {
        $required = new AiModelDto(id: 'o3', name: 'o3', driver: 'openai', supportsReasoning: true, reasoningRequired: true);
        $optional = new AiModelDto(id: 'claude', name: 'Claude', driver: 'openrouter', supportsReasoning: true);

        $this->assertSame(AiReasoningEffort::Low, AiReasoningEffort::Off->forModel($required));
        $this->assertSame(AiReasoningEffort::Off, AiReasoningEffort::Off->forModel($optional));
        $this->assertNull(AiReasoningEffort::High->forModel(new AiModelDto(id: 'gpt-4o', name: 'GPT-4o', driver: 'openai')));
        $this->assertNull(AiReasoningEffort::High->forModel(null));
        $this->assertSame(AiReasoningEffort::Medium, AiReasoningEffort::resolve(null, null));
        $this->assertSame(AiReasoningEffort::High, AiReasoningEffort::resolve(AiReasoningEffort::High, AiReasoningEffort::Off));
    }

    #[Test]
    public function openrouter_flags_reasoning_from_supported_parameters_and_ids_keep_their_colons(): void
    {
        config(['ai.drivers' => ['openrouter' => ['enabled' => true, 'api_key' => 'test-key']]]);
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            ['id' => 'openai/o3', 'supported_parameters' => ['reasoning', 'tools']],
            ['id' => 'anthropic/claude-sonnet-4.5', 'supported_parameters' => ['include_reasoning']],
            ['id' => 'openai/gpt-5-chat', 'supported_parameters' => ['reasoning']],
            ['id' => 'meta-llama/llama-3.3-70b-instruct:free', 'supported_parameters' => ['tools']],
        ]])]);

        $flags = collect((new OpenRouterDriver(config('ai.drivers.openrouter')))->getModels())
            ->mapWithKeys(fn (AiModelDto $model) => [$model->id => [$model->supportsReasoning, $model->reasoningRequired]]);

        $this->assertEquals([
            'openai/o3' => [true, true],
            'anthropic/claude-sonnet-4.5' => [true, false],
            'openai/gpt-5-chat' => [true, false],
            'meta-llama/llama-3.3-70b-instruct:free' => [false, false],
        ], $flags->all());
        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', (new ModelRegistry)->findModel('openrouter:meta-llama/llama-3.3-70b-instruct:free')?->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(BaseAiDriver $driver, ?string $reasoning): array
    {
        $method = new ReflectionMethod($driver, 'buildChatParams');

        return $method->invoke($driver, 'some/model', [['role' => 'user', 'content' => 'Hi']], [], ['max_tokens' => 1024, 'reasoning' => $reasoning]);
    }
}
