<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Dto\StreamEvent;
use Illuminate\Support\Facades\Cache;

abstract class BaseAiDriver implements AiDriverInterface
{
    protected array $tools = [];

    protected int $modelCacheTtl = 43200;

    protected string $name = '';

    protected array $statusMessages = [
        'get_block_list' => 'Discovering available blocks...',
        'get_block_schemas' => 'Analyzing block structures...',
        'search_assets' => 'Searching for media assets...',
        'get_mentioned_content' => 'Fetching referenced content...',
    ];

    public function __construct(
        protected array $config = []
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }

    public function getModels(): array
    {
        return Cache::remember(
            "ai.models.{$this->getName()}",
            $this->modelCacheTtl,
            fn () => $this->fetchModels()
        );
    }

    abstract protected function fetchModels(): array;

    public function getDefaultModel(): ?AiModelDto
    {
        $models = $this->getModels();

        return $models[0] ?? null;
    }

    public function registerTool(AiToolInterface $tool): self
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function registerTools(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->registerTool($tool);
        }

        return $this;
    }

    public function getToolDefinitions(): array
    {
        return array_values(array_map(
            fn (AiToolInterface $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->inputSchema(),
                ],
            ],
            $this->tools
        ));
    }

    public function callTool(string $toolName, array $input): mixed
    {
        if (! isset($this->tools[$toolName])) {
            throw new \InvalidArgumentException("Unknown tool: {$toolName}");
        }

        return $this->tools[$toolName]->execute($input);
    }

    protected function emitStatus(string $message): StreamEvent
    {
        return StreamEvent::status($message);
    }

    protected function emitDelta(string $content): StreamEvent
    {
        return StreamEvent::delta($content);
    }

    protected function emitDone(string $content, ?array $data = null): StreamEvent
    {
        return StreamEvent::done($content, $data);
    }

    protected function emitError(string $message): StreamEvent
    {
        return StreamEvent::error($message);
    }

    protected function getHumanStatus(string $toolName): string
    {
        return $this->statusMessages[$toolName] ?? "Processing {$toolName}...";
    }
}
