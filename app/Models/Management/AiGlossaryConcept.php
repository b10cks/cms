<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One meaning across languages: a glossary row. Its terms are what each language says.
 * `key` is the optional stable ID a sheet row can name.
 *
 * @property string $id
 * @property string $glossary_id
 * @property string|null $key
 * @property string|null $note
 * @property bool $keep_exact also decides whether Use and Avoid match with exact case
 */
class AiGlossaryConcept extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['glossary_id', 'key', 'note', 'keep_exact'];

    protected $casts = ['keep_exact' => 'boolean'];

    public function glossary(): BelongsTo
    {
        return $this->belongsTo(AiGlossary::class, 'glossary_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AiGlossaryTerm::class, 'concept_id');
    }
}
