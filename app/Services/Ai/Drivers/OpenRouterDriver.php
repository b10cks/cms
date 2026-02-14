<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Dto\AiModelDto;
use Arr;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenRouterDriver extends BaseAiDriver
{
    protected string $name = 'openrouter';

    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    public function isConfigured(): bool
    {
        return !empty($this->config['api_key']);
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
                    supportsTools: in_array('tools', $supportedParams),
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

        $postData = json_encode($params);
        $headers = [
            'Authorization: Bearer ' . $this->config['api_key'],
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'HTTP-Referer: ' . ($this->config['site_url'] ?? config('app.url')),
            'X-Title: ' . ($this->config['site_name'] ?? config('app.name')),
        ];

        set_time_limit(0);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "{$this->baseUrl}/chat/completions",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '',
        ]);

        $buffer = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            \Log::error('OpenRouter: Curl error', ['error' => $curlError]);
            yield $this->emitError("Connection error: {$curlError}");

            return;
        }

        if ($httpCode !== 200) {
            \Log::error('OpenRouter: HTTP error', ['code' => $httpCode, 'body' => substr($buffer ?? '', 0, 500)]);
            yield $this->emitError("API error: HTTP {$httpCode}");

            return;
        }

        yield from $this->parseStreamBuffer($buffer, $modelId, $messages, $tools, $options);
    }

    protected function parseStreamBuffer(
        string $buffer,
        string $modelId,
        array $messages,
        array $tools,
        array $options
    ): Generator {
        $lines = explode("\n", $buffer);

        $fullContent = '';
        $toolCalls = [];
        $finalFinishReason = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || $line === 'data: [DONE]') {
                continue;
            }

            if (!Str::startsWith($line, 'data: ')) {
                continue;
            }

            $jsonStr = substr($line, 6);
            $data = json_decode($jsonStr, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            if (!isset($data['choices'][0])) {
                continue;
            }

            $choice = $data['choices'][0];
            $delta = $choice['delta'] ?? [];
            $finishReason = $choice['finish_reason'] ?? null;

            if ($finishReason) {
                $finalFinishReason = $finishReason;
            }

            if (isset($delta['content']) && $delta['content'] !== null && $delta['content'] !== '') {
                $fullContent .= $delta['content'];
                yield $this->emitDelta($delta['content']);
            }

            if (isset($delta['tool_calls']) && \is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $toolCallDelta) {
                    $index = $toolCallDelta['index'] ?? \count($toolCalls);

                    if (!isset($toolCalls[$index])) {
                        $toolCalls[$index] = [
                            'id' => $toolCallDelta['id'] ?? ('call_' . uniqid()),
                            'type' => 'function',
                            'function' => [
                                'name' => $toolCallDelta['function']['name'] ?? '',
                                'arguments' => '',
                            ],
                        ];
                    }

                    if (!empty($toolCallDelta['id'])) {
                        $toolCalls[$index]['id'] = $toolCallDelta['id'];
                    }

                    if (!empty($toolCallDelta['function']['name'])) {
                        $toolCalls[$index]['function']['name'] = $toolCallDelta['function']['name'];
                    }

                    if (isset($toolCallDelta['function']['arguments'])) {
                        $toolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                    }
                }
            }
        }

        if ($finalFinishReason === 'tool_calls' && !empty($toolCalls)) {
            $toolCalls = array_values($toolCalls);

            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $argumentsJson = $toolCall['function']['arguments'];
                $toolInput = json_decode($argumentsJson, true) ?? [];

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
                    \Log::error('OpenRouter: Tool failed', [
                        'tool' => $toolName,
                        'error' => $e->getMessage(),
                    ]);
                    yield $this->emitError("Tool '{$toolName}' failed: {$e->getMessage()}");

                    return;
                }
            }

            yield from $this->stream($modelId, $messages, $tools, $options);

            return;
        }

        yield $this->emitDone($fullContent);
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
