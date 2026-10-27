<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Exceptions\ProviderCallFailed;
use App\Services\Ai\Run\RunOptions;
use App\Services\Ai\Run\TextDelta;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Generator;

class BedrockDriver extends BaseAiDriver
{
    protected string $name = 'bedrock';

    protected ?BedrockRuntimeClient $client = null;

    protected function getClient(): BedrockRuntimeClient
    {
        if ($this->client === null) {
            $this->client = new BedrockRuntimeClient([
                'region' => $this->config['region'] ?? config('services.bedrock.region'),
                'version' => 'latest',
                'credentials' => [
                    'key' => $this->config['key'] ?? config('services.bedrock.key'),
                    'secret' => $this->config['secret'] ?? config('services.bedrock.secret'),
                ],
            ]);
        }

        return $this->client;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['key']) || ! empty(config('services.bedrock.key'));
    }

    protected function fetchModels(): array
    {
        $models = [];

        foreach ($this->config['models'] ?? [] as $modelConfig) {
            $models[] = new AiModelDto(
                id: $modelConfig['id'],
                name: $modelConfig['name'],
                driver: $this->name,
                description: $modelConfig['description'] ?? null,
                contextWindow: $modelConfig['context_window'] ?? [],
                inputCost: $modelConfig['input_cost'] ?? 0.0,
                outputCost: $modelConfig['output_cost'] ?? 0.0,
                capabilities: $modelConfig['capabilities'] ?? [],
                supportsStreaming: $modelConfig['supports_streaming'] ?? true,
                supportsTools: $modelConfig['supports_tools'] ?? true,
                supportsVision: $modelConfig['supports_vision'] ?? false,
            );
        }

        return $models;
    }

    public function turn(Transcript $transcript, ToolSet $tools, RunOptions $options): Generator
    {
        $modelId = $options->model;
        $messages = $transcript->messages();

        $this->ensureVisionSupportForMessages($modelId, $messages);

        ['system' => $systemPrompt, 'messages' => $converted] = $this->convertMessages($messages);

        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $this->clampMaxTokens($options->maxTokens, $this->findModelDto($modelId)),
            'messages' => $converted,
            'temperature' => $options->temperature,
        ];

        if ($systemPrompt !== '') {
            // Send the system prompt as a cacheable block. Our system prompts
            // are large and identical across requests, so an ephemeral cache
            // breakpoint lets Anthropic reuse it instead of re-reading it every
            // call. Sub-minimum prompts simply are not cached — no downside.
            $body['system'] = [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        if (! $tools->isEmpty() && $this->supportsTools($modelId)) {
            $body['tools'] = $this->convertTools($tools->definitions());
        }

        $text = '';
        $calls = [];
        $inputTokens = 0;
        $outputTokens = 0;

        try {
            $response = $this->getClient()->invokeModelWithResponseStream([
                'modelId' => $modelId,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($body),
            ]);

            foreach ($response->get('body') as $event) {
                $chunk = json_decode($event['chunk']['bytes'], true);

                switch ($chunk['type'] ?? null) {
                    case 'message_start':
                        $inputTokens = (int) ($chunk['message']['usage']['input_tokens'] ?? 0);
                        break;
                    case 'content_block_start':
                        if (($chunk['content_block']['type'] ?? null) === 'tool_use') {
                            $calls[$chunk['index']] = [
                                'id' => $chunk['content_block']['id'],
                                'name' => $chunk['content_block']['name'],
                                'input' => '',
                            ];
                        }
                        break;
                    case 'content_block_delta':
                        $delta = $chunk['delta'] ?? [];

                        if (($delta['type'] ?? null) === 'text_delta') {
                            $text .= $delta['text'];
                            yield new TextDelta($delta['text']);
                        } elseif (($delta['type'] ?? null) === 'input_json_delta' && isset($calls[$chunk['index']])) {
                            $calls[$chunk['index']]['input'] .= $delta['partial_json'] ?? '';
                        }
                        break;
                    case 'message_delta':
                        $outputTokens = (int) ($chunk['usage']['output_tokens'] ?? $outputTokens);
                        break;
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderCallFailed("bedrock call failed: {$e->getMessage()}", previous: $e);
        }

        ksort($calls);

        return new TurnResult(
            $text,
            array_values(array_map(static fn (array $call) => ToolCall::fromJson($call['id'], $call['name'], $call['input']), $calls)),
            new TurnUsage($inputTokens, $outputTokens),
        );
    }

    /**
     * Split chat-style messages into the Anthropic shape: a single `system`
     * string (Anthropic carries it outside the message list) plus the user and
     * assistant turns.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{system: string, messages: array<int, array<string, mixed>>}
     */
    protected function convertMessages(array $messages): array
    {
        $converted = [];
        $systemPrompt = '';

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemPrompt .= ($systemPrompt ? "\n\n" : '').$this->stringifyContent($message['content']);

                continue;
            }

            if ($message['role'] === 'tool') {
                // Anthropic carries tool results as user content blocks; results
                // of one turn share a single user message.
                $result = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message['tool_call_id'] ?? '',
                    'content' => (string) ($message['content'] ?? ''),
                ];
                $last = array_key_last($converted);

                if ($last !== null && $converted[$last]['role'] === 'user' && \is_array($converted[$last]['content'])
                    && ($converted[$last]['content'][0]['type'] ?? null) === 'tool_result') {
                    $converted[$last]['content'][] = $result;
                } else {
                    $converted[] = ['role' => 'user', 'content' => [$result]];
                }

                continue;
            }

            if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
                $blocks = ($message['content'] ?? '') !== '' ? [['type' => 'text', 'text' => $message['content']]] : [];

                foreach ($message['tool_calls'] as $call) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'],
                        'name' => $call['name'],
                        'input' => $call['arguments'] === [] ? new \stdClass : $call['arguments'],
                    ];
                }

                $converted[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            if ($message['role'] === 'user' || $message['role'] === 'assistant') {
                $converted[] = [
                    'role' => $message['role'],
                    'content' => $this->convertContentBlocks($message['content'] ?? ''),
                ];
            }
        }

        return ['system' => $systemPrompt, 'messages' => $converted];
    }

    /**
     * Anthropic carries the system prompt as a plain string, so multi-part
     * system content collapses to its text parts.
     */
    private function stringifyContent(mixed $content): string
    {
        if (\is_string($content)) {
            return $content;
        }

        if (! \is_array($content)) {
            return '';
        }

        return trim(implode("\n", array_values(array_filter(array_map(
            fn (array $part): ?string => ($part['type'] ?? null) === 'text' ? (string) ($part['text'] ?? '') : null,
            $content,
        )))));
    }

    /**
     * Convert our provider-neutral content parts into Anthropic content
     * blocks. String content stays a string (the API accepts both shapes).
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function convertContentBlocks(mixed $content): string|array
    {
        if (\is_string($content)) {
            return $content;
        }

        if (! \is_array($content)) {
            return '';
        }

        return array_values(array_filter(array_map(fn (array $part): ?array => match ($part['type'] ?? null) {
            'text' => [
                'type' => 'text',
                'text' => (string) ($part['text'] ?? ''),
            ],
            'image' => [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => (string) ($part['mime_type'] ?? 'application/octet-stream'),
                    'data' => (string) ($part['data'] ?? ''),
                ],
            ],
            default => null,
        }, $content)));
    }

    protected function convertTools(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'input_schema' => $tool['function']['parameters'],
            ];
        }, $tools);
    }

    protected function resetClient(): void
    {
        $this->client = null;
    }
}
