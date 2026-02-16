<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Dto\AiModelDto;
use Arr;
use Generator;
use Illuminate\Support\Facades\Http;
use OpenAI;
use OpenAI\Client;

class OpenRouterDriver extends BaseAiDriver
{
    protected string $name = 'openrouter';

    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    protected ?Client $client = null;

    public function isConfigured(): bool
    {
        return !empty($this->config['api_key']);
    }

    public function withApiKey(string $apiKey): static
    {
        $clone = clone $this;
        $clone->config['api_key'] = $apiKey;
        $clone->client = null;

        return $clone;
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $factory = OpenAI::factory()
                ->withBaseUri($this->baseUrl)
                ->withApiKey($this->config['api_key'])
                ->withHttpHeader('HTTP-Referer', $this->config['site_url'] ?? config('app.url'))
                ->withHttpHeader('X-Title', $this->config['site_name'] ?? config('app.name'));

            $this->client = $factory->make();
        }

        return $this->client;
    }

    protected function fetchModels(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(30)
                ->get("{$this->baseUrl}/models");

            if (!$response->ok()) {
                return [];
            }

            $data = $response->json();
            $models = [];

            foreach ($data['data'] ?? [] as $modelData) {
                $supportedParams = $modelData['supported_parameters'] ?? [];
                $models[] = new AiModelDto(
                    id: $modelData['id'],
                    name: $modelData['name'] ?? $modelData['id'],
                    driver: $this->name,
                    contextWindow: [
                        'input' => $modelData['context_length'] ?? 4096,
                        'output' => $modelData['top_provider']['max_completion_tokens'] ?? 4096,
                    ],
                    inputCost: $this->parseCost($modelData['pricing']['prompt'] ?? '0'),
                    outputCost: $this->parseCost($modelData['pricing']['completion'] ?? '0'),
                    capabilities: $this->determineCapabilities($modelData),
                    supportsStreaming: true,
                    supportsTools: \in_array('tools', $supportedParams),
                    supportsVision: ($modelData['architecture']['modality'] ?? '') === 'text+image->text',
                );
            }

            return Arr::sort($models, fn(AiModelDto $model) => $model->name);
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function parseCost(string $costString): float
    {
        if (empty($costString) || $costString === '0') {
            return 0.0;
        }

        return (float) $costString * 1000000;
    }

    protected function determineCapabilities(array $modelData): array
    {
        $capabilities = ['text'];

        if (($modelData['architecture']['modality'] ?? '') === 'text+image->text') {
            $capabilities[] = 'vision';
        }

        if (\in_array('tools', $modelData['supported_parameters'] ?? [])) {
            $capabilities[] = 'tools';
        }

        return $capabilities;
    }

    public function stream(
        string $modelId,
        array $messages,
        array $tools = [],
        array $options = []
    ): Generator {
        $client = $this->getClient();

        $params = [
            'model' => $modelId,
            'messages' => $messages,
            'stream' => true,
        ];

        $supportsTools = $this->supportsTools($modelId);

        if (!empty($tools) && $supportsTools) {
            $params['tools'] = $tools;
        }
        if (isset($options['max_tokens'])) {
            $params['max_tokens'] = $options['max_tokens'];
        }
        if (isset($options['temperature'])) {
            $params['temperature'] = $options['temperature'];
        }

        try {
            $stream = $client->chat()->createStreamed($params);

            $fullContent = '';
            $toolCalls = [];
            $reasoningContent = '';

            foreach ($stream as $response) {
                $delta = $response->choices[0]->delta ?? null;

                if (isset($delta->reasoning_content) && !empty($delta->reasoning_content)) {
                    $reasoningContent .= $delta->reasoning_content;
                    yield $this->emitStatus($delta->reasoning_content);
                }

                if ($delta?->content) {
                    $fullContent .= $delta->content;
                    yield $this->emitDelta($delta->content);
                }

                if ($delta?->toolCalls) {
                    foreach ($delta->toolCalls as $toolCallDelta) {
                        $index = $toolCallDelta->index;

                        if (!isset($toolCalls[$index])) {
                            $toolCalls[$index] = [
                                'id' => $toolCallDelta->id ?? ('call_' . uniqid()),
                                'type' => $toolCallDelta->type ?? 'function',
                                'function' => [
                                    'name' => $toolCallDelta->function?->name ?? '',
                                    'arguments' => '',
                                ],
                            ];
                        }

                        if (!empty($toolCallDelta->id)) {
                            $toolCalls[$index]['id'] = $toolCallDelta->id;
                        }

                        if ($toolCallDelta->function?->name) {
                            $toolCalls[$index]['function']['name'] = $toolCallDelta->function->name;
                        }

                        if ($toolCallDelta->function?->arguments) {
                            $toolCalls[$index]['function']['arguments'] .= $toolCallDelta->function->arguments;
                        }
                    }
                }

                if ($response->choices[0]->finishReason === 'tool_calls' && !empty($toolCalls)) {
                    $toolCalls = array_values($toolCalls);

                    foreach ($toolCalls as $toolCall) {
                        $toolName = $toolCall['function']['name'];
                        $toolInput = json_decode($toolCall['function']['arguments'], true) ?? [];

                        yield $this->emitStatus($this->getHumanStatus($toolName));

                        try {
                            $toolResult = $this->callTool($toolName, $toolInput);

                            $messages[] = [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [$toolCall],
                            ];
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCall['id'],
                                'content' => json_encode($toolResult),
                            ];
                        } catch (\Throwable $e) {
                            yield $this->emitError("Tool '{$toolName}' failed: {$e->getMessage()}");

                            return;
                        }
                    }

                    $toolCalls = [];

                    yield from $this->stream($modelId, $messages, $tools, $options);

                    return;
                }
            }

            yield $this->emitDone($fullContent);
        } catch (\Throwable $e) {
            yield $this->emitError($e->getMessage());
        }
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->config['api_key']}",
            'Content-Type' => 'application/json',
            'HTTP-Referer' => $this->config['site_url'] ?? config('app.url'),
            'X-Title' => $this->config['site_name'] ?? config('app.name'),
        ];
    }

    protected function supportsTools(string $modelId): bool
    {
        $models = $this->getModels();
        foreach ($models as $model) {
            if ($model->id === $modelId) {
                return $model->supportsTools;
            }
        }

        return false;
    }
}
