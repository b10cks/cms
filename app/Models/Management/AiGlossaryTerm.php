<?php

namespace App\Models\Management;

use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one language of a concept says. `preferred` null with `forbidden` set is a ban.
 * {@see GlossaryConcepts} is the only writer.
 *
 * @property string $id
 * @property string $concept_id
 * @property string $locale normalized, one per concept
 * @property string|null $preferred Use
 * @property list<string>|null $forbidden Avoid
 * @property list<string>|null $aliases Also written as
 */
class AiGlossaryTerm extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['concept_id', 'locale', 'preferred', 'forbidden', 'aliases'];

    protected $casts = [
        'forbidden' => 'array',
        'aliases' => 'array',
    ];

    public function scopeInGlossary(Builder $query, string $glossaryId): Builder
    {
        return $query->whereIn('concept_id', AiGlossaryConcept::query()->select('id')->where('glossary_id', $glossaryId));
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(AiGlossaryConcept::class, 'concept_id');
    }
}
