<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Dto\AiModelDto;
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

    public function stream(
        string $modelId,
        array $messages,
        array $tools = [],
        array $options = []
    ): Generator {
        $client = $this->getClient();

        $params = [
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
        ];

        $this->ensureVisionSupportForMessages($modelId, $messages);

        ['system' => $systemPrompt, 'messages' => $converted] = $this->convertMessages($messages);

        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $this->clampMaxTokens((int) ($options['max_tokens'] ?? 4096), $this->findModelDto($modelId)),
            'messages' => $converted,
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

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        if (! empty($tools) && $this->supportsTools($modelId)) {
            $body['tools'] = $this->convertTools($tools);
        }

        $params['body'] = json_encode($body);

        try {
            $response = $client->invokeModelWithResponseStream($params);
            $stream = $response->get('body');

            $fullContent = '';
            $toolCalls = [];
            $currentToolCall = null;

            foreach ($stream as $event) {
                $chunk = json_decode($event['chunk']['bytes'], true);

                $type = $chunk['type'] ?? null;

                match ($type) {
                    'content_block_delta' => $delta = $chunk['delta'] ?? null,
                    'content_block_start' => $start = $chunk['content_block'] ?? null,
                    'content_block_stop' => $stop = true,
                    'message_delta' => $messageDelta = $chunk['delta'] ?? null,
                    default => null,
                };

                if (isset($delta) && $delta['type'] === 'text_delta') {
                    $fullContent .= $delta['text'];
                    yield $this->emitDelta($delta['text']);
                }

                if (isset($delta) && $delta['type'] === 'input_json_delta') {
                    if ($currentToolCall !== null) {
                        $toolCalls[$currentToolCall]['input'] .= $delta['partial_json'] ?? '';
                    }
                }

                if (isset($start) && ($start['type'] ?? null) === 'tool_use') {
                    $currentToolCall = $start['index'];
                    $toolCalls[$currentToolCall] = [
                        'id' => $start['id'],
                        'name' => $start['name'],
                        'input' => '',
                    ];
                }

                if (isset($messageDelta) && ($messageDelta['stop_reason'] ?? null) === 'tool_use' && ! empty($toolCalls)) {
                    foreach ($toolCalls as $toolCall) {
                        $toolName = $toolCall['name'];
                        $toolInput = json_decode($toolCall['input'], true) ?? [];

                        yield $this->emitStatus($this->getHumanStatus($toolName));

                        try {
                            $toolResult = $this->callTool($toolName, $toolInput);

                            $messages[] = [
                                'role' => 'assistant',
                                'content' => [
                                    [
                                        'type' => 'tool_use',
                                        'id' => $toolCall['id'],
                                        'name' => $toolName,
                                        'input' => $toolInput,
                                    ],
                                ],
                            ];

                            $messages[] = [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'tool_result',
                                        'tool_use_id' => $toolCall['id'],
                                        'content' => json_encode($toolResult),
                                    ],
                                ],
                            ];
                        } catch (\Throwable $e) {
                            yield $this->reportError($e, 'A tool call failed.', ['tool' => $toolName]);

                            return;
                        }
                    }

                    yield from $this->stream($modelId, $messages, $tools, $options);

                    return;
                }

                unset($delta, $start, $stop, $messageDelta);
            }

            yield $this->emitDone($fullContent);
        } catch (\Throwable $e) {
            yield $this->reportError($e, 'The AI provider returned an error.', ['model' => $modelId]);
        }
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
            'tool_use', 'tool_result' => $part,
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
