<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiChangesetItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiChangesetItem */
class AiChangesetItemResource extends JsonResource
{
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
            'glossary_findings' => $this->glossary_findings ?? [],
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
