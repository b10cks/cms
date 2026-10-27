<?php

namespace App\Services\Ai\Run;

use App\Services\Ai\Contracts\AiToolInterface;

/** The tools a run may call, keyed by name. */
final class ToolSet
{
    /** @var array<string, AiToolInterface> */
    private array $tools = [];

    /**
     * @param  iterable<AiToolInterface>  $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Narrow to the given names. Never widens: unknown names are ignored.
     *
     * @param  list<string>  $names
     */
    public function only(array $names): self
    {
        return new self(array_intersect_key($this->tools, array_flip($names)));
    }

    /**
     * Tool definitions in the OpenAI function shape; drivers convert as needed.
     *
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function definitions(): array
    {
        return array_values(array_map(static fn (AiToolInterface $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ],
        ], $this->tools));
    }

    /**
     * Execute a call and return the JSON tool result. {@see ToolError}s become
     * an error result for the model; other exceptions propagate.
     */
    public function execute(ToolCall $call): string
    {
        $tool = $this->tools[$call->name] ?? null;

        try {
            if ($tool === null) {
                throw new ToolError("Unknown tool: {$call->name}");
            }

            $result = $tool->execute($call->arguments);
        } catch (ToolError $e) {
            $result = ['error' => $e->getMessage()];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
