<?php

namespace App\Services\Ai\Drivers;

use App\Enums\AiReasoningEffort;
use App\Services\Ai\Dto\AiModelDto;
use Arr;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Http;
use OpenAI;
use OpenAI\Client;

class OpenRouterDriver extends BaseAiDriver
{
    use StreamsOpenAiTurns;

    protected string $name = 'openrouter';

    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    protected ?Client $client = null;

    /**
     * OpenRouter exposes a long tail of models, many without tool support, so an
     * unknown model is assumed not tool-capable rather than the base default.
     */
    protected bool $toolsSupportedByDefault = false;

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    protected function resetClient(): void
    {
        $this->client = null;
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $factory = OpenAI::factory()
                ->withBaseUri($this->baseUrl)
                ->withApiKey($this->config['api_key'])
                ->withHttpClient(new GuzzleClient([
                    'connect_timeout' => (float) ($this->config['connect_timeout'] ?? 10),
                    'read_timeout' => (float) ($this->config['stream_idle_timeout'] ?? 120),
                ]))
                ->withHttpHeader('HTTP-Referer', $this->config['site_url'] ?? config('app.url'))
                ->withHttpHeader('X-Title', $this->config['site_name'] ?? config('app.name'));

            $this->client = $factory->make();
        }

        return $this->client;
    }

    /**
     * OpenRouter normalises sampling/length parameters server-side and caps the
     * output to each model's real limit, so clamping against our (often
     * guessed) catalogue ceiling would only shrink valid budgets. Pass through.
     */
    protected function clampMaxTokens(int $requested, ?AiModelDto $model): int
    {
        return max(1, $requested);
    }

    /** OpenRouter's unified switch: an effort level, or reasoning disabled for off. */
    protected function reasoningParams(AiReasoningEffort $effort): array
    {
        return ['reasoning' => $effort === AiReasoningEffort::Off ? ['enabled' => false] : ['effort' => $effort->value]];
    }

    /** Anthropic and Gemini need explicit `cache_control` breakpoints via OpenRouter. */
    protected function usesCacheBreakpoints(string $model): bool
    {
        return str_starts_with($model, 'anthropic/') || str_starts_with($model, 'google/gemini');
    }

    /**
     * The provider-reported cost in USD of one finished generation, or null
     * when the lookup fails (data can lag behind the request).
     */
    public function generationCost(string $generationId): ?float
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(15)
                ->get("{$this->baseUrl}/generation", ['id' => $generationId]);
        } catch (\Throwable) {
            return null;
        }

        $cost = $response->ok() ? $response->json('data.total_cost') : null;

        return is_numeric($cost) ? (float) $cost : null;
    }

    protected function fetchModels(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(30)
                ->get("{$this->baseUrl}/models");

            if (! $response->ok()) {
                return [];
            }

            $data = $response->json();
            $models = [];

            foreach ($data['data'] ?? [] as $modelData) {
                $supportedParams = $modelData['supported_parameters'] ?? [];
                $supportsReasoning = \in_array('reasoning', $supportedParams) || \in_array('include_reasoning', $supportedParams);
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
                    supportsJsonMode: \in_array('response_format', $supportedParams)
                        || \in_array('structured_outputs', $supportedParams),
                    supportsReasoning: $supportsReasoning,
                    reasoningRequired: $supportsReasoning && $this->reasoningRequired($modelData['id']),
                );
            }

            return Arr::sort($models, fn (AiModelDto $model) => $model->name);
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

    protected function getHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->config['api_key']}",
            'Content-Type' => 'application/json',
            'HTTP-Referer' => $this->config['site_url'] ?? config('app.url'),
            'X-Title' => $this->config['site_name'] ?? config('app.name'),
        ];
    }
}
