<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Exceptions\ProviderCallFailed;
use App\Services\Ai\Run\RunOptions;
use App\Services\Ai\Run\TextDelta;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use Generator;
use OpenAI\Client;

/**
 * One turn over the OpenAI-compatible chat API, shared by the OpenAI and
 * OpenRouter drivers. The using driver provides `getClient()`.
 */
trait StreamsOpenAiTurns
{
    abstract protected function getClient(): Client;

    public function turn(Transcript $transcript, ToolSet $tools, RunOptions $options): Generator
    {
        $params = $this->buildChatParams(
            $options->model,
            $this->openAiMessages($transcript),
            $tools->definitions(),
            $options->toArray(),
        );

        return yield from $this->streamOpenAiTurn($this->getClient(), $params);
    }

    /**
     * Convert the transcript into OpenAI-compatible chat messages, with
     * provider-neutral content parts mapped to the vision shape.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function openAiMessages(Transcript $transcript): array
    {
        return array_map(static fn (array $message): array => match ($message['role']) {
            'assistant' => [
                'role' => 'assistant',
                'content' => ($message['content'] ?? '') === '' ? null : $message['content'],
            ] + (isset($message['tool_calls']) ? ['tool_calls' => array_map(static fn (array $call) => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => ToolCall::fromArray($call)->argumentsJson(),
                ],
            ], $message['tool_calls'])] : []),
            'tool' => [
                'role' => 'tool',
                'tool_call_id' => $message['tool_call_id'] ?? '',
                'content' => (string) ($message['content'] ?? ''),
            ],
            default => ['role' => $message['role'], 'content' => $message['content'] ?? ''],
        }, $transcript->messages());
    }

    /**
     * Run one streamed OpenAI-compatible chat completion and collect its text,
     * tool calls and usage. Reasoning deltas are dropped: they are never shown
     * to users.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, TextDelta, mixed, TurnResult>
     */
    protected function streamOpenAiTurn(Client $client, array $params): Generator
    {
        $text = '';
        $calls = [];
        $usage = null;

        try {
            foreach ($client->chat()->createStreamed($params) as $response) {
                if ($response->usage !== null) {
                    $usage = $response->usage;
                }

                $delta = $response->choices[0]->delta ?? null;

                if ($delta?->content) {
                    $text .= $delta->content;
                    yield new TextDelta($delta->content);
                }

                foreach ($delta?->toolCalls ?? [] as $toolCallDelta) {
                    $index = $toolCallDelta->index ?? \count($calls);
                    $calls[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

                    if (! empty($toolCallDelta->id)) {
                        $calls[$index]['id'] = $toolCallDelta->id;
                    }

                    if ($toolCallDelta->function?->name) {
                        $calls[$index]['name'] = $toolCallDelta->function->name;
                    }

                    $calls[$index]['arguments'] .= $toolCallDelta->function?->arguments ?? '';
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderCallFailed("{$this->getName()} call failed: {$e->getMessage()}", previous: $e);
        }

        ksort($calls);

        return new TurnResult(
            $text,
            array_values(array_map(
                static fn (array $call) => ToolCall::fromJson($call['id'] !== '' ? $call['id'] : 'call_'.uniqid(), $call['name'], $call['arguments']),
                $calls,
            )),
            new TurnUsage((int) ($usage?->promptTokens ?? 0), (int) ($usage?->completionTokens ?? 0)),
        );
    }
}
