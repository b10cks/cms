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
 * @property bool $keep_exact
 * @property bool|null $case_sensitive only where a row of the older format differs from Keep exact, see {@see caseSensitive()}
 */
class AiGlossaryConcept extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['glossary_id', 'key', 'note', 'keep_exact', 'case_sensitive'];

    protected $casts = ['keep_exact' => 'boolean', 'case_sensitive' => 'boolean'];

    /**
     * Whether Use and Avoid match with exact case. Keep exact decides, unless the older
     * format set its own value: a checkbox apart from Keep exact shipped there, so customer
     * rows can have either without the other. No new-format control writes this.
     */
    public function caseSensitive(): bool
    {
        return $this->case_sensitive ?? $this->keep_exact;
    }

    public function glossary(): BelongsTo
    {
        return $this->belongsTo(AiGlossary::class, 'glossary_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AiGlossaryTerm::class, 'concept_id');
    }
}
