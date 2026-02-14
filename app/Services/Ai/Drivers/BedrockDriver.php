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

        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => $this->convertMessages($messages),
        ];

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
                            yield $this->emitError("Tool '{$toolName}' failed: {$e->getMessage()}");

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
            yield $this->emitError($e->getMessage());
        }
    }

    protected function convertMessages(array $messages): array
    {
        $converted = [];
        $systemPrompt = '';

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemPrompt .= ($systemPrompt ? "\n\n" : '').$message['content'];

                continue;
            }

            if ($message['role'] === 'tool') {
                continue;
            }

            if ($message['role'] === 'user') {
                $converted[] = [
                    'role' => 'user',
                    'content' => $message['content'],
                ];
            }

            if ($message['role'] === 'assistant') {
                $converted[] = [
                    'role' => 'assistant',
                    'content' => $message['content'],
                ];
            }
        }

        return $converted;
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

    protected function supportsTools(string $modelId): bool
    {
        $models = $this->getModels();
        foreach ($models as $model) {
            if ($model->id === $modelId) {
                return $model->supportsTools;
            }
        }

        return true;
    }
}
