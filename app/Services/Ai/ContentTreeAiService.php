<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use App\Services\Ai\StreamEventType;
use App\Services\Ai\Tools\GetBlockListTool;
use App\Services\Ai\Tools\GetMentionedContentTool;
use Generator;
use Illuminate\Support\Str;

class ContentTreeAiService
{
    protected ModelRegistry $registry;

    public function __construct(ModelRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function stream(
        Space $space,
        string $prompt,
        array $tree = [],
        array $mentions = [],
        $aiConfig = null
    ): Generator {
        app()->offsetSet('currentSpace', $space);

        if (! $aiConfig) {
            $aiConfig = $space->defaultAiConfig ?? $space->aiConfig;
        }

        if (! $aiConfig) {
            yield StreamEvent::error('No AI configuration found for this space');

            return;
        }

        $modelId = $this->resolveModelId($space, $aiConfig);
        [$driverName, $modelIdentifier] = $this->parseModelId($modelId);

        $driver = $this->registry->getDriverForSpace($driverName, $space);

        if (! $driver) {
            yield StreamEvent::error("Driver '{$driverName}' not found or not enabled");

            return;
        }

        $tools = $this->createTools($space);

        foreach ($tools as $tool) {
            $driver->registerTool($tool);
        }

        $promptBuilder = new SystemPromptBuilder($aiConfig);

        $messages = $this->buildMessages($prompt, $tree, $mentions, $promptBuilder);
        $toolDefinitions = $driver->getToolDefinitions();

        $options = [
            'max_tokens' => $aiConfig->max_tokens ?? 32768,
            'temperature' => (float) ($aiConfig->temperature ?? 0.7),
        ];

        yield from $driver->stream(
            $modelIdentifier,
            $messages,
            $toolDefinitions,
            $options,
        );
    }

    protected function createTools(Space $space): array
    {
        return [
            (new GetBlockListTool)->setSpace($space)->setTypes(['root', 'universal', 'single']),
            (new GetMentionedContentTool)->setSpace($space),
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

    protected function buildMessages(
        string $prompt,
        array $tree,
        array $mentions,
        SystemPromptBuilder $promptBuilder
    ): array {
        $systemPrompt = $promptBuilder->forContentTreeGeneration();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $userContent = $prompt;

        $context = [
            'tree' => $tree,
            'mentions' => $mentions,
        ];

        if (! empty($context)) {
            $userContent .= "\n\n## Current Content Tree\n" . json_encode($tree, JSON_PRETTY_PRINT);

            if (! empty($mentions)) {
                $userContent .= "\n\n## Mentioned Items\n" . json_encode($mentions, JSON_PRETTY_PRINT);
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }
}
