<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\ModelRegistry;
use Generator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiStreamServiceTest extends TestCase
{
    #[Test]
    public function it_attaches_selected_config_prompt_to_generated_interactions(): void
    {
        $driver = new CapturingAiDriver();
        $service = new AiStreamService(new CapturingModelRegistry($driver));
        $space = new Space();
        $config = new SpaceAiConfig([
            'driver' => 'testing',
            'model' => 'model',
            'system_prompt' => 'Follow the selected config.',
        ]);

        $service->generate($space, 'Base instructions.', 'Hello', aiConfig: $config);

        $systemPrompt = $driver->messages[0]['content'];

        $this->assertStringContainsString('Base instructions.', $systemPrompt);
        $this->assertStringContainsString('Follow the selected config.', $systemPrompt);
    }

    #[Test]
    public function it_attaches_selected_config_prompt_to_streamed_system_prompt_interactions(): void
    {
        $driver = new CapturingAiDriver();
        $service = new AiStreamService(new CapturingModelRegistry($driver));
        $space = new Space();
        $config = new SpaceAiConfig([
            'driver' => 'testing',
            'model' => 'model',
            'system_prompt' => 'Stream with this behavior.',
        ]);

        iterator_to_array($service->streamWithSystemPrompt(
            $space,
            'Base instructions.',
            'Hello',
            aiConfig: $config,
        ));

        $systemPrompt = $driver->messages[0]['content'];

        $this->assertStringContainsString('Base instructions.', $systemPrompt);
        $this->assertStringContainsString('Stream with this behavior.', $systemPrompt);
    }
}

class CapturingModelRegistry extends ModelRegistry
{
    public function __construct(
        protected AiDriverInterface $driver,
    ) {}

    public function getDriverForSpace(string $driverName, Space $space): ?AiDriverInterface
    {
        return $this->driver;
    }
}

class CapturingAiDriver implements AiDriverInterface
{
    public array $messages = [];

    public function getName(): string
    {
        return 'testing';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getModels(): array
    {
        return [];
    }

    public function getDefaultModel(): ?AiModelDto
    {
        return null;
    }

    public function registerTool(AiToolInterface $tool): self
    {
        return $this;
    }

    public function registerTools(array $tools): self
    {
        return $this;
    }

    public function stream(
        string $modelId,
        array $messages,
        array $tools = [],
        array $options = []
    ): Generator {
        $this->messages = $messages;

        yield StreamEvent::done('ok');
    }

    public function callTool(string $toolName, array $input): mixed
    {
        return null;
    }

    public function getToolDefinitions(): array
    {
        return [];
    }

    public function supportsToolCalls(string $modelId): bool
    {
        return false;
    }
}
