<?php

namespace App\Services\Ai\Run;

use App\Services\Ai\Contracts\SseEvent;

final readonly class RunEvent implements SseEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $runId,
        public int $seq,
        public RunEventType $type,
        public array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'run_id' => $this->runId, 'seq' => $this->seq] + $this->payload;
    }

    public function toJsonLine(): string
    {
        return 'data: '.json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function isTerminal(): bool
    {
        return $this->type->endsStream();
    }
}
