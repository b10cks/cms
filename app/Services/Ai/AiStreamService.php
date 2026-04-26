<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Dto\StreamEventType;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use App\Services\Ai\Tools\GetBlockListTool;
use App\Services\Ai\Tools\GetBlockSchemasTool;
use App\Services\Ai\Tools\GetMentionedContentTool;
use App\Services\Ai\Tools\SearchAssetsTool;
use Generator;
use Illuminate\Support\Str;

class AiStreamService
{
    protected ModelRegistry $registry;

    public function __construct(ModelRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function stream(
        Space $space,
        string $prompt,
        array $context = [],
        array $files = [],
        $aiConfig = null,
    ): Generator {
        app()->offsetSet('currentSpace', $space);

        if (!$aiConfig) {
            $aiConfig = $space->defaultAiConfig;
        }

        if (!$aiConfig) {
            yield StreamEvent::error('No AI configuration found for this space');

            return;
        }

        $modelId = $this->resolveModelId($space, $aiConfig);
        [$driverName, $modelIdentifier] = $this->parseModelId($modelId);

        $driver = $this->registry->getDriverForSpace($driverName, $space);

        if (!$driver) {
            yield StreamEvent::error("Driver '{$driverName}' not found or not enabled");

            return;
        }

        $tools = $this->createTools($space);

        foreach ($tools as $tool) {
            $driver->registerTool($tool);
        }

        $promptBuilder = new SystemPromptBuilder($aiConfig);

        $messages = $this->buildMessages($prompt, $context, $files, $promptBuilder);
        $toolDefinitions = $driver->getToolDefinitions();

        $options = [
            'max_tokens' => $aiConfig->max_tokens ?? 32768,
            'temperature' => (float) ($aiConfig->temperature ?? 0.7),
        ];

        yield from $driver->stream($modelIdentifier, $messages, $toolDefinitions, $options);
    }

    protected function createTools(Space $space): array
    {
        return [
            new GetBlockListTool()
                ->setSpace($space)
                ->setTypes(['nestable', 'universal']),
            new GetBlockSchemasTool()->setSpace($space),
            new SearchAssetsTool()->setSpace($space),
            new GetMentionedContentTool()->setSpace($space),
        ];
    }

    protected function resolveModelId(Space $space, $aiConfig = null): string
    {
        if ($aiConfig && $aiConfig->driver && $aiConfig->model) {
            return "{$aiConfig->driver}:{$aiConfig->model}";
        }

        $modelId = $space->settings->ai['model'] ?? null;

        if ($modelId && Str::contains($modelId, ':')) {
            return $modelId;
        }

        foreach ($this->registry->getEnabledDrivers() as $driver) {
            $defaultModel = $driver->getDefaultModel();
            if ($defaultModel) {
                return $defaultModel->getFullId();
            }
        }

        return 'openai:gpt-4o-mini';
    }

    protected function parseModelId(string $fullId): array
    {
        if (Str::contains($fullId, ':')) {
            return explode(':', $fullId, 2);
        }

        return ['openai', $fullId];
    }

    public function streamWithSystemPrompt(
        Space $space,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
        $aiConfig = null,
    ): Generator {
        $aiConfig = $aiConfig ?: $space->defaultAiConfig;
        if (!$aiConfig) {
            yield StreamEvent::error('No AI configuration found');

            return;
        }

        $modelId = $this->resolveModelId($space, $aiConfig);
        [$driverName, $modelIdentifier] = $this->parseModelId($modelId);
        $driver = $this->registry->getDriverForSpace($driverName, $space);

        if (!$driver) {
            yield StreamEvent::error("Driver '{$driverName}' not found");

            return;
        }

        $systemPrompt = (new SystemPromptBuilder($aiConfig))->withConfiguredPrompt($systemPrompt);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        yield from $driver->stream($modelIdentifier, $messages, [], array_merge([
            'max_tokens' => $aiConfig->max_tokens ?? 4096,
            'temperature' => (float) ($aiConfig->temperature ?? 0.7),
        ], $options));
    }

    public function generate(
        Space $space,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
        $aiConfig = null,
    ): ?string {
        $aiConfig = $aiConfig ?: $space->defaultAiConfig;
        $modelId = $this->resolveModelId($space, $aiConfig);
        [$driverName, $modelIdentifier] = $this->parseModelId($modelId);

        $driver = $this->registry->getDriverForSpace($driverName, $space);

        if (!$driver) {
            return null;
        }

        $systemPrompt = (new SystemPromptBuilder($aiConfig))->withConfiguredPrompt($systemPrompt);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $defaultOptions = [
            'max_tokens' => $aiConfig?->max_tokens ?? 4096,
            'temperature' => (float) ($aiConfig?->temperature ?? 0.7),
        ];

        $fullContent = '';

        foreach ($driver->stream($modelIdentifier, $messages, [], array_merge($defaultOptions, $options)) as $event) {
            if ($event->type === StreamEventType::Delta) {
                $fullContent .= $event->content;
            } elseif ($event->type === StreamEventType::Done) {
                return $event->content ?: $fullContent;
            } elseif ($event->type === StreamEventType::Error) {
                return null;
            }
        }

        return $fullContent ?: null;
    }

    protected function buildMessages(
        string $prompt,
        array $context,
        array $files,
        SystemPromptBuilder $promptBuilder,
    ): array {
        $systemPrompt = $promptBuilder->forContentInteraction();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $userContent = $prompt;

        if (!empty($context)) {
            $userContent .= "\n\n## Context\n" . json_encode($context);
        }

        if (!empty($files)) {
            $userContent .= "\n\n## Attached Files\n" . json_encode($files, JSON_PRETTY_PRINT);
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }
}
