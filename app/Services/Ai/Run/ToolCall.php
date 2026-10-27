<?php

namespace App\Services\Ai\Run;

/** One tool call requested by the model in a turn. */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}

    /**
     * Decode raw provider arguments. Invalid JSON becomes an empty object so the
     * tool can answer with a validation error instead of failing the run.
     */
    public static function fromJson(string $id, string $name, string $json): self
    {
        $decoded = json_decode($json === '' ? '{}' : $json, true);

        return new self($id, $name, \is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array{id: string, name: string, arguments?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['arguments'] ?? []);
    }

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }

    public function argumentsJson(): string
    {
        return json_encode($this->arguments === [] ? new \stdClass : $this->arguments, JSON_UNESCAPED_UNICODE);
    }
}
