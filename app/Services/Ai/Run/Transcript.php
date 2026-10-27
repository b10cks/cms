<?php

namespace App\Services\Ai\Run;

/**
 * Provider-neutral message list of a run. Drivers translate it into their wire
 * format; the run service appends to it and checkpoints it after every step.
 *
 * Message shapes:
 * - `system` / `user`: `content` is a string or a list of parts (`text`, `image`)
 * - `assistant`: `content` string plus optional `tool_calls`
 * - `tool`: `tool_call_id`, `name` and string `content`
 *
 * @phpstan-type Message array{role: string, content?: mixed, tool_calls?: list<array{id: string, name: string, arguments: array<string, mixed>}>, tool_call_id?: string, name?: string}
 */
final class Transcript
{
    /**
     * @param  list<Message>  $messages
     */
    public function __construct(private array $messages = []) {}

    /**
     * @param  list<Message>  $messages
     */
    public static function fromArray(array $messages): self
    {
        return new self(array_values($messages));
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return list<Message>
     */
    public function toArray(): array
    {
        return $this->messages;
    }

    public function appendSystem(string $content): self
    {
        $this->messages[] = ['role' => 'system', 'content' => $content];

        return $this;
    }

    /**
     * @param  string|list<array<string, mixed>>  $content
     */
    public function appendUser(string|array $content): self
    {
        $this->messages[] = ['role' => 'user', 'content' => $content];

        return $this;
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function appendAssistant(string $text, array $toolCalls = []): self
    {
        $message = ['role' => 'assistant', 'content' => $text];

        if ($toolCalls !== []) {
            $message['tool_calls'] = array_map(static fn (ToolCall $call) => $call->toArray(), $toolCalls);
        }

        $this->messages[] = $message;

        return $this;
    }

    public function appendToolResult(ToolCall $call, string $content): self
    {
        $this->messages[] = [
            'role' => 'tool',
            'tool_call_id' => $call->id,
            'name' => $call->name,
            'content' => $content,
        ];

        return $this;
    }

    /**
     * Tool calls of the last assistant message that have no result yet. A run
     * that crashed between a provider turn and its tool results resumes here
     * instead of repeating the provider call.
     *
     * @return list<ToolCall>
     */
    public function pendingToolCalls(): array
    {
        $answered = [];

        for ($i = \count($this->messages) - 1; $i >= 0; $i--) {
            $message = $this->messages[$i];

            if ($message['role'] === 'tool') {
                $answered[$message['tool_call_id'] ?? ''] = true;

                continue;
            }

            if ($message['role'] !== 'assistant') {
                return [];
            }

            return array_values(array_filter(
                array_map(static fn (array $call) => ToolCall::fromArray($call), $message['tool_calls'] ?? []),
                static fn (ToolCall $call) => ! isset($answered[$call->id]),
            ));
        }

        return [];
    }

    public function lastAssistantText(): string
    {
        for ($i = \count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'assistant') {
                return (string) ($this->messages[$i]['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Whether the transcript ends with a user or tool message, so the next step
     * is a provider call.
     */
    public function awaitsModel(): bool
    {
        $last = end($this->messages);

        return $last !== false && \in_array($last['role'], ['user', 'tool'], true);
    }
}
