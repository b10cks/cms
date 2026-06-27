<?php

namespace App\Services\LemonSqueezy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class LemonSqueezyService
{
    private Client $client;

    private string $apiKey;

    private string $storeId;

    public function __construct()
    {
        $this->apiKey = config('services.lemonsqueezy.api_key', '');
        $this->storeId = config('services.lemonsqueezy.store_id', '');

        $this->client = new Client([
            'base_uri' => 'https://api.lemonsqueezy.com/v1/',
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ],
            'timeout' => 30,
        ]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->storeId);
    }

    public function createCheckout(
        string $variantId,
        string $email,
        string $name,
        array $customData = [],
        ?string $redirectUrl = null,
    ): array {
        $payload = [
            'data' => [
                'type' => 'checkouts',
                'attributes' => [
                    'checkout_data' => [
                        'email' => $email,
                        'name' => $name,
                        'custom' => $customData,
                    ],
                    'product_options' => [
                        'redirect_url' => $redirectUrl ?? config('app.url'),
                        'enabled_variants' => [$variantId],
                    ],
                ],
                'relationships' => [
                    'store' => [
                        'data' => ['type' => 'stores', 'id' => $this->storeId],
                    ],
                    'variant' => [
                        'data' => ['type' => 'variants', 'id' => $variantId],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('checkouts', ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        return [
            'checkout_id' => $data['data']['id'] ?? null,
            'checkout_url' => $data['data']['attributes']['url'] ?? null,
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $response = $this->client->get("subscriptions/{$subscriptionId}");
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['data'] ?? [];
    }

    public function listSubscriptions(array $filters = []): array
    {
        $subscriptions = [];
        $url = 'subscriptions?'.http_build_query(['filter[store_id]' => $this->storeId, ...$filters]);

        do {
            $response = $this->client->get($url);
            $payload = json_decode($response->getBody()->getContents(), true);

            $subscriptions = [...$subscriptions, ...($payload['data'] ?? [])];
            $url = $payload['links']['next'] ?? null;
        } while ($url);

        return $subscriptions;
    }

    /**
     * List subscription invoices for the store, optionally filtered (e.g. by
     * customer or subscription). Follows pagination links.
     *
     * @param  array<string, mixed>  $filters  LS filter params, e.g. ['filter[customer_id]' => 123]
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(array $filters = []): array
    {
        $invoices = [];
        $url = 'subscription-invoices?'.http_build_query(['filter[store_id]' => $this->storeId, ...$filters]);

        do {
            $response = $this->client->get($url);
            $payload = json_decode($response->getBody()->getContents(), true);

            $invoices = [...$invoices, ...($payload['data'] ?? [])];
            $url = $payload['links']['next'] ?? null;
        } while ($url);

        return $invoices;
    }

    /**
     * Flatten a LemonSqueezy subscription-invoice into the shape the app exposes.
     *
     * @param  array<string, mixed>  $lsInvoice
     * @return array<string, mixed>
     */
    public function normalizeInvoice(array $lsInvoice): array
    {
        $attrs = $lsInvoice['attributes'] ?? [];

        return [
            'id' => (string) ($lsInvoice['id'] ?? ''),
            'total' => (int) data_get($attrs, 'total', 0),
            'total_formatted' => data_get($attrs, 'total_formatted'),
            'currency' => data_get($attrs, 'currency'),
            'status' => data_get($attrs, 'status'),
            'status_formatted' => data_get($attrs, 'status_formatted'),
            'refunded' => (bool) data_get($attrs, 'refunded', false),
            'card_brand' => data_get($attrs, 'card_brand'),
            'card_last_four' => data_get($attrs, 'card_last_four'),
            'billing_reason' => data_get($attrs, 'billing_reason'),
            'invoice_url' => data_get($attrs, 'urls.invoice_url'),
            'created_at' => $this->parseDate(data_get($attrs, 'created_at'))?->toIso8601String(),
        ];
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        $response = $this->client->delete("subscriptions/{$subscriptionId}");
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['data'] ?? [];
    }

    public function resumeSubscription(string $subscriptionId): array
    {
        $payload = [
            'data' => [
                'type' => 'subscriptions',
                'id' => $subscriptionId,
                'attributes' => ['cancelled' => false],
            ],
        ];
        $response = $this->client->patch("subscriptions/{$subscriptionId}", ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['data'] ?? [];
    }

    public function changeSubscriptionVariant(string $subscriptionId, string $newVariantId): array
    {
        $payload = [
            'data' => [
                'type' => 'subscriptions',
                'id' => $subscriptionId,
                'attributes' => ['variant_id' => (int) $newVariantId],
            ],
        ];
        $response = $this->client->patch("subscriptions/{$subscriptionId}", ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['data'] ?? [];
    }

    public function getCustomerPortalUrl(string $subscriptionId): ?string
    {
        try {
            $response = $this->client->get("subscriptions/{$subscriptionId}/customer-portal");
            $data = json_decode($response->getBody()->getContents(), true);

            return $data['data']['attributes']['url'] ?? null;
        } catch (GuzzleException $e) {
            Log::warning('Failed to get LS customer portal URL', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.lemonsqueezy.webhook_secret', '');
        if (empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function mapStatus(string $lsStatus): string
    {
        return match ($lsStatus) {
            'active' => 'active',
            'on_trial' => 'on_trial',
            'paused' => 'paused',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            default => $lsStatus,
        };
    }

    public function normalizeSubscription(array $lsSubscription): array
    {
        $attrs = $lsSubscription['attributes'] ?? [];
        $quantity = data_get($attrs, 'first_subscription_item.quantity', 1);
        $billingPortalUrl = data_get($attrs, 'urls.customer_portal');
        $customerId = $attrs['customer_id'] ?? null;
        $variantName = trim((string) ($attrs['variant_name'] ?? ''));
        $productName = trim((string) ($attrs['product_name'] ?? ''));
        $fallbackName = $variantName !== ''
            ? $variantName
            : ($productName !== '' ? $productName : 'Subscription');

        return [
            'lemon_squeezy_id' => isset($lsSubscription['id']) ? (string) $lsSubscription['id'] : null,
            'ls_customer_id' => $customerId !== null ? (string) $customerId : null,
            'billing_portal_url' => $billingPortalUrl ?: null,
            'name' => $fallbackName,
            'status' => $this->mapStatus($attrs['status'] ?? 'active'),
            'variant_id' => (string) ($attrs['variant_id'] ?? ''),
            'product_id' => (string) ($attrs['product_id'] ?? ''),
            'quantity' => (int) $quantity,
            'renews_at' => $this->parseDate(data_get($attrs, 'renews_at')),
            'ends_at' => $this->parseDate(data_get($attrs, 'ends_at')),
            'trial_ends_at' => $this->parseDate(data_get($attrs, 'trial_ends_at')),
            'attributes' => $attrs,
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! \is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
