<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class OpenRouterKeyManager
{
    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    public function __construct(
        protected ?string $managementKey = null
    ) {
        $this->managementKey ??= config('ai.drivers.openrouter.management_key');
    }

    public function provisionKey(
        Space $space,
        ?float $limit = null,
        ?string $limitReset = null,
        ?Carbon $expiresAt = null
    ): SpaceAiKey {
        if (empty($this->managementKey)) {
            throw new \RuntimeException('OpenRouter management key is not configured.');
        }

        $payload = [
            'name' => "b10cks-{$space->id}-" . now()->format('Ymd'),
        ];

        if ($limit !== null) {
            $payload['limit'] = $limit;
        }

        if ($limitReset !== null) {
            $payload['limit_interval'] = $limitReset;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->managementKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/keys", $payload);

        if (!$response->created()) {
            throw new \RuntimeException(
                "Failed to provision OpenRouter key: HTTP {$response->status()} - {$response->body()}"
            );
        }

        $data = $response->json();
        $apiKey = $data['key'] ?? $data['data']['key'] ?? null;
        $keyHash = $data['key_hash'] ?? $data['data']['hash'] ?? null;

        if (!$apiKey) {
            throw new \RuntimeException('OpenRouter API did not return a key.');
        }

        return SpaceAiKey::create([
            'space_id' => $space->id,
            'driver' => 'openrouter',
            'key_hash' => hash('sha256', $apiKey),
            'encrypted_key' => Crypt::encryptString($apiKey),
            'name' => $payload['name'],
            'external_key_hash' => $keyHash,
            'limit' => $limit,
            'limit_reset' => $limitReset,
            'expires_at' => $expiresAt,
        ]);
    }

    public function revokeKey(SpaceAiKey $key): void
    {
        if (empty($this->managementKey)) {
            throw new \RuntimeException('OpenRouter management key is not configured.');
        }

        $hash = $key->external_key_hash ?? $key->key_hash;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->managementKey}",
            'Content-Type' => 'application/json',
        ])->delete("{$this->baseUrl}/keys/{$hash}");

        if (!$response->ok() && $response->status() !== 404) {
            throw new \RuntimeException(
                "Failed to revoke OpenRouter key: HTTP {$response->status()}"
            );
        }

        $key->update(['disabled_at' => now()]);
    }

    public function updateKeyLimit(SpaceAiKey $key, ?float $limit, ?string $limitReset = null): void
    {
        if (empty($this->managementKey)) {
            throw new \RuntimeException('OpenRouter management key is not configured.');
        }

        $hash = $key->external_key_hash ?? $key->key_hash;

        $payload = [];
        if ($limit !== null) {
            $payload['limit'] = $limit;
        }
        if ($limitReset !== null) {
            $payload['limit_interval'] = $limitReset;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->managementKey}",
            'Content-Type' => 'application/json',
        ])->patch("{$this->baseUrl}/keys/{$hash}", $payload);

        if (!$response->ok()) {
            throw new \RuntimeException(
                "Failed to update OpenRouter key: HTTP {$response->status()}"
            );
        }

        $key->update([
            'limit' => $limit ?? $key->limit,
            'limit_reset' => $limitReset ?? $key->limit_reset,
        ]);
    }
}
