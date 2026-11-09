<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\TranslationUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TranslationUnit
 *
 * @resourceProperty id shape=string
 * @resourceProperty source_locale shape=string Lowercase.
 * @resourceProperty target_locale shape=string Lowercase.
 * @resourceProperty source_text shape=string The source text the translation was written against.
 * @resourceProperty target_text shape=string
 * @resourceProperty origin shape=string `human`, written or corrected by a person, or `ai_accepted`, AI text a person published.
 * @resourceProperty content_id shape=string The translation entry the pair was published in.
 * @resourceProperty field_path shape=string The field within that entry.
 * @resourceProperty content shape=@TranslationUnitContent? The entry, null when it no longer exists.
 * @resourceProperty created_at shape=string
 * @resourceProperty updated_at shape=string
 */
class TranslationUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_locale' => $this->source_locale,
            'target_locale' => $this->target_locale,
            'source_text' => $this->source_text,
            'target_text' => $this->target_text,
            'origin' => $this->origin,
            'content_id' => $this->content_id,
            'field_path' => $this->field_path,
            'content' => $this->whenLoaded('content', fn () => $this->content ? [
                'id' => $this->content->id,
                'name' => $this->content->name,
                'full_slug' => $this->content->full_slug,
                'source_id' => $this->content->i18n_parent_id,
                'trashed' => $this->content->trashed(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
