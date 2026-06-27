<?php

namespace App\Services\Ai\Dto;

readonly class AiModelDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $driver,
        public ?string $description = null,
        public array $contextWindow = [],
        public float $inputCost = 0.0,
        public float $outputCost = 0.0,
        public array $capabilities = [],
        public bool $supportsStreaming = true,
        public bool $supportsTools = true,
        public bool $supportsVision = false,
        public bool $supportsJsonMode = false,
    ) {}

    public function getFullId(): string
    {
        return "{$this->driver}:{$this->id}";
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_id' => $this->getFullId(),
            'name' => $this->name,
            'driver' => $this->driver,
            'description' => $this->description,
            'context_window' => $this->contextWindow,
            'input_cost' => $this->inputCost,
            'output_cost' => $this->outputCost,
            'capabilities' => $this->capabilities,
            'supports_streaming' => $this->supportsStreaming,
            'supports_tools' => $this->supportsTools,
            'supports_vision' => $this->supportsVision,
            'supports_json_mode' => $this->supportsJsonMode,
        ];
    }
}
