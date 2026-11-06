<?php

namespace App\Http\Resources\Management\Ai;

use App\Services\Ai\Knowledge\Concepts\ConceptRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConceptRow
 *
 * @resourceProperty id shape=string The concept id.
 * @resourceProperty key shape=string?
 * @resourceProperty keep_exact shape=boolean The wording stays as written, in exact case. A target language's own Use still wins.
 * @resourceProperty note shape=string? One per row. At most 200 characters on a write, longer older notes stay readable.
 * @resourceProperty languages shape=map:@AiGlossaryConceptRowLanguage By locale code.
 * @resourceProperty coverage shape=map:string By available locale: `own`, `fallback` (a base language serves it) or `missing`.
 */
class AiGlossaryConceptRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'keep_exact' => $this->keepExact,
            'note' => $this->note,
            'languages' => (object) $this->languages,
            'coverage' => (object) $this->coverage,
        ];
    }
}
