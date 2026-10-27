<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiSkill;
use App\Services\Ai\Skills\SkillParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

/** @mixin AiSkill */
class AiSkillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $files = $this->draft['files'] ?? [];

        try {
            $description = app(SkillParser::class)->parse($files)->description;
        } catch (ValidationException) {
            $description = null;
        }

        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'name' => $this->name,
            'description' => $description,
            'enabled' => $this->enabled,
            'files' => array_map(fn (string $path, string $content) => ['path' => $path, 'content' => $content], array_keys($files), $files),
            'published_revision' => $this->whenLoaded('publishedRevision', fn () => $this->publishedRevision ? [
                'id' => $this->publishedRevision->id,
                'revision' => $this->publishedRevision->revision,
                'published_at' => $this->publishedRevision->created_at?->toIso8601String(),
                'hash' => $this->publishedRevision->hash,
            ] : null),
            'has_unpublished_changes' => $this->whenLoaded('publishedRevision', fn () => $this->publishedRevision?->manifest['files'] != $files),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
