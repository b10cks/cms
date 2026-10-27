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
            $this->openAiMessages($transcript, $this->usesCacheBreakpoints($options->model)),
            $tools->definitions(),
            $options->toArray(),
        );

        return yield from $this->streamOpenAiTurn($this->getClient(), $params);
    }

    /** Whether this model needs explicit `cache_control` breakpoints; the rest cache automatically or not at all. */
    protected function usesCacheBreakpoints(string $model): bool
    {
        return false;
    }

    /**
     * Convert the transcript into OpenAI-compatible chat messages, with
     * provider-neutral content parts mapped to the vision shape. With
     * `$cacheBreakpoints`, a system message is split at its cache breaks into
     * text parts, each break closing a part marked `cache_control`.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function openAiMessages(Transcript $transcript, bool $cacheBreakpoints = false): array
    {
        return array_map(static fn (array $message): array => match ($message['role']) {
            'system' => ['role' => 'system', 'content' => $cacheBreakpoints && ! empty($message['cache_breaks'])
                ? self::cachedParts((string) $message['content'], $message['cache_breaks'])
                : $message['content'] ?? ''],
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
     * @param  list<int>  $breaks
     * @return list<array<string, mixed>>
     */
    private static function cachedParts(string $content, array $breaks): array
    {
        $parts = [];
        $from = 0;

        foreach ([...$breaks, \strlen($content)] as $end) {
            $text = substr($content, $from, $end - $from);
            if (trim($text) !== '') {
                $parts[] = ['type' => 'text', 'text' => $text];
            }
            $from = $end;
            if ($parts !== [] && $end < \strlen($content)) {
                $parts[array_key_last($parts)]['cache_control'] = ['type' => 'ephemeral'];
            }
        }

        return $parts;
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
        $generationId = null;

        try {
            foreach ($client->chat()->createStreamed($params) as $response) {
                $generationId ??= $response->id;
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
            new TurnUsage(
                (int) ($usage?->promptTokens ?? 0),
                (int) ($usage?->completionTokens ?? 0),
                cachedTokens: (int) ($usage?->promptTokensDetails?->cachedTokens ?? 0),
                generationId: $generationId,
            ),
        );
    }
}
