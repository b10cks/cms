<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Enums\ActionType;
use App\Services\Security\Exceptions\UnsafeUrlException;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;

class WebhookActionHandler extends BaseActionHandler
{
    public function __construct(
        private readonly OutboundUrlGuard $urlGuard = new OutboundUrlGuard(),
    ) {
        $this->type = ActionType::WEBHOOK;
    }

    public function execute(array $config, array $context = []): mixed
    {
        // Validate AFTER template substitution — the URL may contain {{ }}
        // placeholders that are only resolved here, so the raw config value was
        // never enough to trust.
        $url = $this->replaceVariables($config['url'], $context);
        $method = strtoupper((string) $config['method']);

        try {
            $this->urlGuard->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            \Log::warning('Blocked unsafe webhook URL', ['url' => $url, 'reason' => $e->getMessage()]);
            throw $e;
        }

        $headers = $this->replaceVariablesInArray($config['headers'] ?? [], $context);
        $parameters = $this->replaceVariablesInArray($config['parameters'] ?? [], $context);
        $timeout = (int) ($config['timeout_seconds'] ?? 15);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                // Do not follow redirects: a 30x to an internal host would
                // bypass the SSRF check performed above.
                ->withoutRedirecting()
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
