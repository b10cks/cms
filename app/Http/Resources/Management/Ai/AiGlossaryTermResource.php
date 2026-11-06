<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiGlossaryTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One language of a row in the older term shape. A ban shows its first Avoid wording as
 * `term`, and `translations` are the row's other languages that have a Use.
 *
 * @mixin AiGlossaryTerm
 *
 * @resourceProperty translations shape=map:string
 * @resourceProperty case_sensitive shape=boolean Whether Use and Avoid match with exact case. Follows do_not_translate unless this format set it apart.
 * @resourceProperty do_not_translate shape=boolean
 * @resourceProperty forbidden shape=string[]
 * @resourceProperty note shape=string?
 * @resourceProperty concept_id shape=string Links the language variants of one concept.
 * @resourceProperty source_aliases shape=string[] Other spellings of the term in a source. They count as the term itself.
 */
class AiGlossaryTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $concept = $this->concept;
        $translations = [];

        foreach ($concept->terms as $sibling) {
            if ($sibling->id !== $this->id && $sibling->preferred !== null) {
                $translations[$sibling->locale] = $sibling->preferred;
            }
        }

        return [
            'id' => $this->id,
            'source_locale' => $this->locale,
            'term' => $this->preferred ?? ($this->forbidden[0] ?? ''),
            'translations' => (object) $translations,
            'case_sensitive' => $concept->caseSensitive(),
            'do_not_translate' => $concept->keep_exact,
            'forbidden' => $this->forbidden ?? [],
            'note' => $concept->note,
            'concept_id' => $this->concept_id,
            'source_aliases' => $this->aliases ?? [],
        ];
    }
}
