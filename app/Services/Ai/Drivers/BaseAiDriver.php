<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Dto\StreamEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function withApiKey(string $apiKey): static
    {
        $clone = clone $this;
        $clone->config['api_key'] = $apiKey;

        return $clone;
    }

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

    public function supportsToolCalls(string $modelId): bool
    {
        $model = $this->findModelDto($modelId);

        // Mirrors the gate in buildChatParams (and the Bedrock body builder):
        // reasoning-shaped requests drop tools, and some models never support
        // them at all. Tool definitions are only sent when both checks pass.
        return ! $this->shouldShapeForReasoning($modelId, $model)
            && $this->supportsTools($modelId);
    }

    /**
     * Whether the given model can be sent tool definitions. Concrete drivers
     * override this against their model catalogue; the base default is true.
     */
    protected function supportsTools(string $modelId): bool
    {
        return true;
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

    protected function findModelDto(string $modelId): ?AiModelDto
    {
        foreach ($this->getModels() as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Build the request payload for an OpenAI-compatible chat completion,
     * shaping parameters to the target model: clamps the output token budget to
     * the model's known ceiling, swaps in `max_completion_tokens` and drops
     * `temperature`/`system`/`tools` for reasoning models, and requests native
     * JSON mode only for models that advertise support for it.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildChatParams(string $modelId, array $messages, array $tools, array $options): array
    {
        $model = $this->findModelDto($modelId);
        $reasoning = $this->shouldShapeForReasoning($modelId, $model);

        // Prompt caching on this (OpenAI-compatible) path is automatic: OpenAI
        // caches identical prompt prefixes ≥1024 tokens, and OpenRouter applies
        // provider caching for the models that support it. The static system
        // prompt is sent first so it forms a stable, cacheable prefix — no
        // explicit cache_control field is needed here (unlike Anthropic).
        $params = [
            'model' => $modelId,
            'messages' => $reasoning ? $this->demoteSystemMessages($messages) : $messages,
            'stream' => true,
        ];

        if (! empty($tools) && ! $reasoning && $this->supportsTools($modelId)) {
            $params['tools'] = $tools;
        }

        if (isset($options['max_tokens'])) {
            $max = $this->clampMaxTokens((int) $options['max_tokens'], $model);
            $params[$reasoning ? 'max_completion_tokens' : 'max_tokens'] = $max;
        }

        if (isset($options['temperature']) && ! $reasoning) {
            $params['temperature'] = (float) $options['temperature'];
        }

        if (! empty($options['json']) && ($model?->supportsJsonMode ?? false)) {
            $params['response_format'] = ['type' => 'json_object'];
        }

        return $params;
    }

    /**
     * Whether requests for this model must be shaped for the reasoning-model
     * API contract (no temperature/system role, max_completion_tokens, no
     * tools). Only relevant for drivers talking to the provider directly.
     */
    protected function shouldShapeForReasoning(string $modelId, ?AiModelDto $model): bool
    {
        return false;
    }

    protected function clampMaxTokens(int $requested, ?AiModelDto $model): int
    {
        $ceiling = $model->contextWindow['output'] ?? null;

        if (is_numeric($ceiling) && (int) $ceiling > 0) {
            return max(1, min($requested, (int) $ceiling));
        }

        return max(1, $requested);
    }

    /**
     * Fold any system messages into the first user message. Reasoning models
     * (o1/o3) reject the `system` role.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function demoteSystemMessages(array $messages): array
    {
        $system = [];
        $rest = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                $system[] = is_string($message['content'] ?? null)
                    ? $message['content']
                    : json_encode($message['content'] ?? '');

                continue;
            }

            $rest[] = $message;
        }

        if ($system === []) {
            return $messages;
        }

        $prefix = implode("\n\n", $system);

        foreach ($rest as $index => $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $rest[$index]['content'] = $prefix."\n\n".$message['content'];

                return $rest;
            }
        }

        array_unshift($rest, ['role' => 'user', 'content' => $prefix]);

        return $rest;
    }

    /**
     * Log the real exception and return a generic, reference-tagged error event.
     * Never leak provider/exception detail to the client.
     *
     * @param  array<string, mixed>  $context
     */
    protected function reportError(\Throwable $e, string $userMessage, array $context = []): StreamEvent
    {
        $ref = (string) Str::uuid();

        Log::error('AI driver error', array_merge($context, [
            'driver' => $this->getName(),
            'ref' => $ref,
            'message' => $e->getMessage(),
            'exception' => $e,
        ]));

        return StreamEvent::error("{$userMessage} (ref: {$ref})");
    }
}
