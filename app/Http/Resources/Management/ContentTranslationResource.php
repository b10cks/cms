<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
class ContentTranslationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'full_slug' => $this->full_slug,
            'language_iso' => $this->language_iso,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
