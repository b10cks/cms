<?php

namespace App\Http\Resources\Management;

use App\Models\Space\FieldPlugin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin FieldPlugin
 */
class FieldPluginResource extends JsonResource
{
    /**
     * Include the stored bundle code (show endpoint only — never in listings).
     */
    public bool $withCode = false;

    public function withCode(): static
    {
        $this->withCode = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description,
            'status' => $this->status(),
            'dev_mode' => $this->dev_mode,
            'dev_url' => $this->dev_url,
            'code' => $this->when($this->withCode, fn () => $this->code),
            'code_hash' => $this->code_hash,
            'code_size' => $this->code_size,
            'published_at' => $this->published_at?->toIso8601String(),
            'sandbox_url' => $this->sandboxUrl($request),
            'manifest' => $this->manifest,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function status(): string
    {
        if ($this->dev_mode && $this->dev_url) {
            return 'dev';
        }

        return $this->isPublished() ? 'published' : 'draft';
    }

    protected function sandboxUrl(Request $request): ?string
    {
        // Mirrors the sandbox endpoint's gate: inactive plugins 404 there anyway.
        if (! $this->isPublished() || ! $this->is_active) {
            return null;
        }

        return URL::signedRoute('mgmt.spaces.field-plugins.sandbox', [
            'space' => $request->route('space')?->id ?? $request->input('space'),
            'fieldPlugin' => $this->id,
            'v' => $this->code_hash,
        ]);
    }
}
