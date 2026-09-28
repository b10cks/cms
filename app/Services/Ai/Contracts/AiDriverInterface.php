<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Dto\AiModelDto;
use Generator;

interface AiDriverInterface
{
    public function getName(): string;

    public function isConfigured(): bool;

    public function isEnabled(): bool;

    public function getModels(): array;

    public function getDefaultModel(): ?AiModelDto;

    public function registerTool(AiToolInterface $tool): self;

    public function registerTools(array $tools): self;

    /**
     * Message content may be a plain string or an array of provider-neutral
     * parts (`text`, `image` with base64 data); drivers translate the parts
     * into their provider's wire format.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function stream(
        string $modelId,
        array $messages,
        array $tools = [],
        array $options = []
    ): Generator;

    public function callTool(string $toolName, array $input): mixed;

    public function getToolDefinitions(): array;

    /**
     * Whether a request for this model will actually carry tool definitions.
     * False when the model is shaped for the reasoning API contract (which
     * forbids tools) or simply does not support tool calling, so callers can
     * build a prompt that does not instruct the model to use tools it lacks.
     */
    public function supportsToolCalls(string $modelId): bool;
}
