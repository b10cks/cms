<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiChangesetItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiChangesetItem
 *
 * @resourceProperty target_label shape=string?
 * @resourceProperty base_version_id shape=string?
 * @resourceProperty path shape=string?
 * @resourceProperty field_type shape=string?
 * @resourceProperty before shape=any The value the agent read.
 * @resourceProperty after shape=any The value the agent wrote or proposed.
 * @resourceProperty final shape=any The accepted value, when an editor changed it.
 * @resourceProperty current shape=any The value now.
 * @resourceProperty status shape=enum:pending|accepted|rejected|conflict
 * @resourceProperty flags shape=@AiReviewFlag[] Reasons this item needs an individual look. Never part of a group accept.
 * @resourceProperty operation_item_id shape=string?
 * @resourceProperty translation_units shape=@AiChangesetTranslationUnit[]
 * @resourceProperty review shape=@AiChangesetItemReview
 * @resourceProperty decided_at shape=date-time?
 */
class AiChangesetItemResource extends JsonResource
{
    /** @param list<array{unit_id: string, reason: string|null, source: string, previous_source: string|null}> $translationUnits */
    public function __construct($resource, private readonly array $translationUnits = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_label' => $this->target_label,
            'base_version_id' => $this->base_version_id,
            'path' => $this->path,
            'field_type' => $this->field_type,
            'before' => $this->before,
            'after' => $this->after,
            'final' => $this->final,
            'current' => $this->current,
            'status' => $this->status->value,
            // Reasons this item needs an individual look; never part of a group accept.
            'flags' => $this->flags ?? [],
            'operation_item_id' => $this->operation_item_id,
            'translation_units' => $this->translationUnits,
            'review' => $this->review,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
