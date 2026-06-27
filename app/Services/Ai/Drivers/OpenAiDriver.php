<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Dto\AiModelDto;
use Generator;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client;

class OpenAiDriver extends BaseAiDriver
{
    protected string $name = 'openai';

    protected ?Client $client = null;

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = OpenAI::factory()
                ->withApiKey($this->config['api_key'] ?? config('openai.api_key'))
                ->withOrganization($this->config['organization'] ?? null)
                ->withHttpClient($this->makeHttpClient())
                ->make();
        }

        return $this->client;
    }

    /**
     * A Guzzle client with bounded connect/idle timeouts so a hung provider
     * cannot stall a streamed request forever. No total `timeout` is set, so
     * legitimately long generations are never truncated mid-stream.
     */
    protected function makeHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'connect_timeout' => (float) ($this->config['connect_timeout'] ?? 10),
            'read_timeout' => (float) ($this->config['stream_idle_timeout'] ?? 120),
        ]);
    }

    protected function shouldShapeForReasoning(string $modelId, ?AiModelDto $model): bool
    {
        if ($model && in_array('reasoning', $model->capabilities, true)) {
            return true;
        }

        return (bool) preg_match('/^o\d/i', $modelId);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']) || ! empty(config('openai.api_key'));
    }

    protected function fetchModels(): array
    {
        try {
            $client = $this->getClient();
            $response = $client->models()->list();

            $allowedPatterns = [
                '/^gpt-4/',
                '/^gpt-4o/',
                '/^o1/',
                '/^o3/',
                '/^chatgpt-4o/',
            ];

            $models = [];
            foreach ($response->data as $model) {
                $isAllowed = false;
                foreach ($allowedPatterns as $pattern) {
                    if (preg_match($pattern, $model->id)) {
                        $isAllowed = true;
                        break;
                    }
                }

                if (! $isAllowed) {
                    continue;
                }

                $modelInfo = $this->getModelInfo($model->id);

                $models[] = new AiModelDto(
                    id: $model->id,
                    name: $this->formatModelName($model->id),
                    driver: $this->name,
                    description: $modelInfo['description'] ?? null,
                    contextWindow: $modelInfo['context_window'] ?? ['input' => 8192, 'output' => 4096],
                    inputCost: $modelInfo['input_cost'] ?? 0.0,
                    outputCost: $modelInfo['output_cost'] ?? 0.0,
                    capabilities: $modelInfo['capabilities'] ?? ['text'],
                    supportsStreaming: $modelInfo['supports_streaming'] ?? true,
                    supportsTools: $modelInfo['supports_tools'] ?? true,
                    supportsVision: $modelInfo['supports_vision'] ?? false,
                    supportsJsonMode: $modelInfo['supports_json_mode'] ?? ! (bool) preg_match('/^o\d/i', $model->id),
                );
            }

            if (empty($models)) {
                return $this->getFallbackModels();
            }

            usort($models, fn ($a, $b) => strcmp($a->name, $b->name));

            return $models;
        } catch (\Throwable $e) {
            return $this->getFallbackModels();
        }
    }

    protected function formatModelName(string $id): string
    {
        $name = str_replace(['gpt-4-', 'gpt-', '-preview', '-instruct'], '', $id);
        $name = str_replace('-', ' ', $name);
        $name = ucwords($name);

        if (str_starts_with($id, 'gpt-4o')) {
            return str_contains($id, 'mini') ? 'GPT-4o Mini' : 'GPT-4o';
        }

        if (str_starts_with($id, 'o1')) {
            return str_contains($id, 'mini') ? 'o1 Mini' : 'o1';
        }

        return $name;
    }

    protected function getModelInfo(string $modelId): array
    {
        $configModels = $this->config['models'] ?? [];

        foreach ($configModels as $modelConfig) {
            if ($modelConfig['id'] === $modelId) {
                return $modelConfig;
            }
        }

        if (str_contains($modelId, 'mini')) {
            return [
                'context_window' => ['input' => 128000, 'output' => 16384],
                'input_cost' => 0.15,
                'output_cost' => 0.60,
                'capabilities' => ['text', 'vision', 'tools'],
                'supports_vision' => true,
            ];
        }

        if (str_starts_with($modelId, 'o1')) {
            return [
                'context_window' => ['input' => 200000, 'output' => 100000],
                'capabilities' => ['text', 'reasoning'],
                'supports_tools' => false,
                'supports_vision' => false,
            ];
        }

        return [
            'context_window' => ['input' => 128000, 'output' => 16384],
            'input_cost' => 2.50,
            'output_cost' => 10.00,
            'capabilities' => ['text', 'vision', 'tools'],
            'supports_vision' => true,
        ];
    }

    protected function getFallbackModels(): array
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
                supportsJsonMode: $modelConfig['supports_json_mode'] ?? ! (bool) preg_match('/^o\d/i', $modelConfig['id']),
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

        $params = $this->buildChatParams($modelId, $messages, $tools, $options);

        try {
            $stream = $client->chat()->createStreamed($params);

            $fullContent = '';
            $toolCalls = [];

            foreach ($stream as $response) {
                $delta = $response->choices[0]->delta ?? null;

                if ($delta?->content) {
                    $fullContent .= $delta->content;
                    yield $this->emitDelta($delta->content);
                }

                if ($delta?->toolCalls) {
                    foreach ($delta->toolCalls as $toolCallDelta) {
                        $index = $toolCallDelta->index;

                        if (! isset($toolCalls[$index])) {
                            $toolCalls[$index] = [
                                'id' => $toolCallDelta->id,
                                'type' => $toolCallDelta->type,
                                'function' => [
                                    'name' => $toolCallDelta->function?->name ?? '',
                                    'arguments' => '',
                                ],
                            ];
                        }

                        if ($toolCallDelta->function?->arguments) {
                            $toolCalls[$index]['function']['arguments'] .= $toolCallDelta->function->arguments;
                        }
                    }
                }

                if ($response->choices[0]->finishReason === 'tool_calls' && ! empty($toolCalls)) {
                    foreach ($toolCalls as $toolCall) {
                        $toolName = $toolCall['function']['name'];
                        $toolInput = json_decode($toolCall['function']['arguments'], true) ?? [];

                        yield $this->emitStatus($this->getHumanStatus($toolName));

                        try {
                            $toolResult = $this->callTool($toolName, $toolInput);
                            $messages[] = [
                                'role' => 'assistant',
                                'tool_calls' => [$toolCall],
                            ];
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCall['id'],
                                'content' => json_encode($toolResult),
                            ];
                        } catch (\Throwable $e) {
                            yield $this->reportError($e, 'A tool call failed.', ['tool' => $toolName]);

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
            yield $this->reportError($e, 'The AI provider returned an error.', ['model' => $modelId]);
        }
    }

    protected function resetClient(): void
    {
        $this->client = null;
    }
}
