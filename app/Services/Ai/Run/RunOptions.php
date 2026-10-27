<?php

namespace App\Services\Ai\Run;

/** Provider call options for every turn of a run. */
final readonly class RunOptions
{
    public function __construct(
        public string $model,
        public int $maxTokens = 4096,
        public float $temperature = 0.3,
        public bool $json = false,
    ) {}

    /**
     * @param  array{model: string, max_tokens?: int, temperature?: float, json?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['model'],
            (int) ($data['max_tokens'] ?? 4096),
            (float) ($data['temperature'] ?? 0.3),
            (bool) ($data['json'] ?? false),
        );
    }

    /**
     * @return array{model: string, max_tokens: int, temperature: float, json: bool}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'json' => $this->json,
        ];
    }
}
