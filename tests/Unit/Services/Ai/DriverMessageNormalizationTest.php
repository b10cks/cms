<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Drivers\BedrockDriver;
use App\Services\Ai\Drivers\OpenAiDriver;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\Dto\AiModelDto;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class DriverMessageNormalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // getModels() caches per driver name; forget so each test's config-model
        // capabilities (vision on/off) actually take effect.
        Cache::forget('ai.models.openai');
        Cache::forget('ai.models.openrouter');
        Cache::forget('ai.models.bedrock');
    }

    #[Test]
    public function openai_driver_maps_normalized_image_parts_to_openai_vision_format(): void
    {
        $driver = new OpenAiDriver($this->driverConfig('openai', 'gpt-4o-mini', true));

        $normalized = $this->invokeMethod($driver, 'normalizeOpenAiMessages', [
            'gpt-4o-mini',
            $this->normalizedMessages(),
        ]);

        $this->assertSame('system prompt', $normalized[0]['content']);
        $this->assertSame('image_url', $normalized[1]['content'][1]['type']);
        $this->assertStringStartsWith(
            'data:image/png;base64,ZmFrZS1pbWFnZQ==',
            $normalized[1]['content'][1]['image_url']['url']
        );
    }

    #[Test]
    public function openai_normalization_is_idempotent_for_already_normalized_messages(): void
    {
        $driver = new OpenAiDriver($this->driverConfig('openai', 'gpt-4o-mini', true));

        $once = $this->invokeMethod($driver, 'normalizeOpenAiMessages', [
            'gpt-4o-mini',
            $this->normalizedMessages(),
        ]);
        $twice = $this->invokeMethod($driver, 'normalizeOpenAiMessages', ['gpt-4o-mini', $once]);

        $this->assertSame($once, $twice);
    }

    #[Test]
    public function openai_driver_rejects_image_parts_for_non_vision_models(): void
    {
        $driver = new OpenAiDriver($this->driverConfig('openai', 'gpt-4o-mini', false));

        $this->expectException(\InvalidArgumentException::class);

        $this->invokeMethod($driver, 'normalizeOpenAiMessages', [
            'gpt-4o-mini',
            $this->normalizedMessages(),
        ]);
    }

    #[Test]
    public function openrouter_driver_maps_normalized_image_parts_to_openai_compatible_vision_format(): void
    {
        $driver = new class($this->driverConfig('openrouter', 'vision-model', true)) extends OpenRouterDriver
        {
            protected function fetchModels(): array
            {
                return [
                    new AiModelDto(
                        id: 'vision-model',
                        name: 'Vision Model',
                        driver: 'openrouter',
                        capabilities: ['text', 'vision'],
                        supportsStreaming: true,
                        supportsTools: true,
                        supportsVision: true,
                    ),
                ];
            }
        };

        $normalized = $this->invokeMethod($driver, 'normalizeOpenAiMessages', [
            'vision-model',
            $this->normalizedMessages(),
        ]);

        $this->assertSame('image_url', $normalized[1]['content'][1]['type']);
        $this->assertStringStartsWith(
            'data:image/png;base64,ZmFrZS1pbWFnZQ==',
            $normalized[1]['content'][1]['image_url']['url']
        );
    }

    #[Test]
    public function bedrock_driver_maps_normalized_image_parts_to_bedrock_vision_format(): void
    {
        $driver = new BedrockDriver($this->driverConfig('bedrock', 'anthropic.claude-3-5-sonnet-20241022-v2:0', true));

        $converted = $this->invokeMethod($driver, 'convertMessages', [
            $this->normalizedMessages(),
        ]);

        $this->assertSame('system prompt', $converted['system']);
        $this->assertSame('image', $converted['messages'][0]['content'][1]['type']);
        $this->assertSame('base64', $converted['messages'][0]['content'][1]['source']['type']);
        $this->assertSame('image/png', $converted['messages'][0]['content'][1]['source']['media_type']);
        $this->assertSame('ZmFrZS1pbWFnZQ==', $converted['messages'][0]['content'][1]['source']['data']);
    }

    #[Test]
    public function bedrock_driver_rejects_image_parts_for_non_vision_models(): void
    {
        $driver = new BedrockDriver($this->driverConfig('bedrock', 'text-model', false));

        $this->expectException(\InvalidArgumentException::class);

        $this->invokeMethod($driver, 'ensureVisionSupportForMessages', [
            'text-model',
            $this->normalizedMessages(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizedMessages(): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'system prompt',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this image'],
                    ['type' => 'image', 'mime_type' => 'image/png', 'data' => 'ZmFrZS1pbWFnZQ=='],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driverConfig(string $driver, string $modelId, bool $supportsVision): array
    {
        $models = [[
            'id' => $modelId,
            'name' => 'Vision Model',
            'supports_vision' => $supportsVision,
            'supports_tools' => true,
            'supports_streaming' => true,
        ]];

        return match ($driver) {
            'openai', 'openrouter' => [
                'api_key' => 'test-key',
                'models' => $models,
            ],
            default => [
                'models' => $models,
            ],
        };
    }

    private function invokeMethod(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
