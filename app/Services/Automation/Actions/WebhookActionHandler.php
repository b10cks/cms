<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Enums\ActionType;
use Illuminate\Support\Facades\Http;

class WebhookActionHandler extends BaseActionHandler
{
    public function __construct()
    {
        $this->type = ActionType::WEBHOOK;
    }

    public function execute(array $config, array $context = []): mixed
    {
        $url = $this->replaceVariables($config['url'], $context);
        $method = strtoupper((string) $config['method']);

        $headers = $this->replaceVariablesInArray($config['headers'] ?? [], $context);
        $parameters = $this->replaceVariablesInArray($config['parameters'] ?? [], $context);
        $timeout = (int) ($config['timeout_seconds'] ?? 15);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->send($method, $url, [
                    $method === 'GET' ? 'query' : 'json' => $parameters,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Webhook request failed with status {$response->status()}: {$response->body()}"
                );
            }

            return [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->json() ?? $response->body(),
            ];

        } catch (\Exception $e) {
            \Log::error('Webhook action failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
