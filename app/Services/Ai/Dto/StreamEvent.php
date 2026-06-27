<?php

namespace App\Services\Ai\Dto;

enum StreamEventType: string
{
    case Status = 'status';
    case Delta = 'delta';
    case Done = 'done';
    case Error = 'error';
}

readonly class StreamEvent
{
    public function __construct(
        public StreamEventType $type,
        public ?string $message = null,
        public ?string $content = null,
        public ?array $data = null,
    ) {}

    public static function status(string $message): self
    {
        return new self(StreamEventType::Status, message: $message);
    }

    public static function delta(string $content): self
    {
        return new self(StreamEventType::Delta, content: $content);
    }

    public static function done(string $content, ?array $data = null): self
    {
        return new self(StreamEventType::Done, content: $content, data: $data);
    }

    public static function error(string $message, ?string $reason = null): self
    {
        return new self(
            StreamEventType::Error,
            message: $message,
            data: $reason !== null ? ['reason' => $reason] : null,
        );
    }

    public function toJsonLine(): string
    {
        $payload = match ($this->type) {
            StreamEventType::Status => [
                'type' => $this->type->value,
                'message' => $this->message,
            ],
            StreamEventType::Delta => [
                'type' => $this->type->value,
                'content' => $this->content,
            ],
            StreamEventType::Done => [
                'type' => $this->type->value,
                'content' => $this->content,
                'data' => $this->data,
            ],
            StreamEventType::Error => array_filter([
                'type' => $this->type->value,
                'message' => $this->message,
                'reason' => $this->data['reason'] ?? null,
            ], static fn ($value) => $value !== null),
        };

        return 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'content' => $this->content,
            'data' => $this->data,
        ];
    }
}
