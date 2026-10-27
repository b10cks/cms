<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $glossary_id
 * @property string $source_locale
 * @property string $term
 * @property array<string, string>|null $translations
 * @property bool $case_sensitive
 * @property bool $do_not_translate
 * @property list<string>|null $forbidden
 * @property string|null $note
 */
class AiGlossaryTerm extends GlobalModel
{
    use HasUlids;

    protected $fillable = [
        'glossary_id',
        'source_locale',
        'term',
        'translations',
        'case_sensitive',
        'do_not_translate',
        'forbidden',
        'note',
    ];

    protected $casts = [
        'translations' => 'array',
        'case_sensitive' => 'boolean',
        'do_not_translate' => 'boolean',
        'forbidden' => 'array',
    ];

    public function glossary(): BelongsTo
    {
        return $this->belongsTo(AiGlossary::class, 'glossary_id');
    }
}
