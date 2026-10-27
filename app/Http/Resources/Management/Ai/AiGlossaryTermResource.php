<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiGlossaryTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiGlossaryTerm */
class AiGlossaryTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_locale' => $this->source_locale,
            'term' => $this->term,
            'translations' => (object) ($this->translations ?? []),
            'case_sensitive' => $this->case_sensitive,
            'do_not_translate' => $this->do_not_translate,
            'forbidden' => $this->forbidden ?? [],
            'note' => $this->note,
        ];
    }
}
