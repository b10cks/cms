<?php

namespace App\Services\Ai\Run;

use App\Enums\AiReasoningEffort;

/** Provider call options for every turn of a run. */
final readonly class RunOptions
{
    public function __construct(
        public string $model,
        public int $maxTokens = 4096,
        public float $temperature = 0.3,
        public bool $json = false,
        /** The level resolved against the model; null sends no reasoning parameter. */
        public ?AiReasoningEffort $reasoning = null,
    ) {}

    /**
     * @param  array{model: string, max_tokens?: int, temperature?: float, json?: bool, reasoning?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['model'],
            (int) ($data['max_tokens'] ?? 4096),
            (float) ($data['temperature'] ?? 0.3),
            (bool) ($data['json'] ?? false),
            AiReasoningEffort::tryFrom((string) ($data['reasoning'] ?? '')),
        );
    }

    /**
     * @return array{model: string, max_tokens: int, temperature: float, json: bool, reasoning: string|null}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'json' => $this->json,
            'reasoning' => $this->reasoning?->value,
        ];
    }
}
