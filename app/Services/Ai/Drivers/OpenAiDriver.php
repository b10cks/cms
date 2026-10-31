<?php

namespace App\Services\Ai\Drivers;

use App\Enums\AiReasoningEffort;
use App\Services\Ai\Dto\AiModelDto;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client;

class OpenAiDriver extends BaseAiDriver
{
    use StreamsOpenAiTurns;

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
        return $this->isReasoningModel($modelId, $model?->capabilities ?? []);
    }

    /** The o-series and anything the catalogue marks with the `reasoning` capability. */
    protected function isReasoningModel(string $modelId, array $capabilities): bool
    {
        return in_array('reasoning', $capabilities, true) || (bool) preg_match('/^o\d/i', $modelId);
    }

    /** OpenAI has no "off": the parameter is left out and the model's own default applies. */
    protected function reasoningParams(AiReasoningEffort $effort): array
    {
        return $effort === AiReasoningEffort::Off ? [] : ['reasoning_effort' => $effort->value];
    }

    protected function makeModel(string $id, string $name, array $info): AiModelDto
    {
        $reasoning = $this->isReasoningModel($id, $info['capabilities'] ?? []);

        return new AiModelDto(
            id: $id,
            name: $name,
            driver: $this->name,
            description: $info['description'] ?? null,
            contextWindow: $info['context_window'] ?? ['input' => 8192, 'output' => 4096],
            inputCost: $info['input_cost'] ?? 0.0,
            outputCost: $info['output_cost'] ?? 0.0,
            capabilities: $info['capabilities'] ?? ['text'],
            supportsStreaming: $info['supports_streaming'] ?? true,
            supportsTools: $info['supports_tools'] ?? true,
            supportsVision: $info['supports_vision'] ?? false,
            supportsJsonMode: $info['supports_json_mode'] ?? ! (bool) preg_match('/^o\d/i', $id),
            supportsReasoning: $reasoning,
            reasoningRequired: $reasoning && $this->reasoningRequired("openai/{$id}"),
        );
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

                $models[] = $this->makeModel($model->id, $this->formatModelName($model->id), $this->getModelInfo($model->id));
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
            $models[] = $this->makeModel($modelConfig['id'], $modelConfig['name'], $modelConfig + ['context_window' => [], 'capabilities' => []]);
        }

        return $models;
    }

    protected function resetClient(): void
    {
        $this->client = null;
    }
}
