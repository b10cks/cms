<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Run\RunOptions;
use App\Services\Ai\Run\TextDelta;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use Generator;

interface AiDriverInterface
{
    public function getName(): string;

    public function isConfigured(): bool;

    public function isEnabled(): bool;

    public function getModels(): array;

    public function getDefaultModel(): ?AiModelDto;

    /**
     * One provider call. Never executes tools; the run service owns the loop.
     * Provider failures throw {@see \App\Services\Ai\Exceptions\ProviderCallFailed}.
     *
     * @return Generator<int, TextDelta, mixed, TurnResult>
     */
    public function turn(Transcript $transcript, ToolSet $tools, RunOptions $options): Generator;

    /**
     * Whether a request for this model will actually carry tool definitions.
     * False when the model is shaped for the reasoning API contract (which
     * forbids tools) or simply does not support tool calling, so callers can
     * build a prompt that does not instruct the model to use tools it lacks.
     */
    public function supportsToolCalls(string $modelId): bool;
}
