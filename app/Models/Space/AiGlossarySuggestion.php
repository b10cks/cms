<?php

namespace App\Models\Space;

use App\Models\Management\AiGlossary;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A glossary term the learning loop drew from an editor's correction of AI
 * output. Someone with `ai.manage` accepts it into a space or team glossary.
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $run_id
 * @property string|null $actor_id
 * @property string $source_locale
 * @property string $term
 * @property array<string, string>|null $translations
 * @property list<string>|null $forbidden
 * @property string|null $note
 * @property array<string, mixed>|null $evidence
 * @property string $status
 * @property string|null $glossary_id
 * @property string|null $decided_by_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AiGlossarySuggestion extends SpaceDatabaseModel
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'space_id',
        'run_id',
        'actor_id',
        'source_locale',
        'term',
        'translations',
        'forbidden',
        'note',
        'evidence',
        'status',
        'glossary_id',
        'decided_by_id',
        'decided_at',
    ];

    protected $casts = [
        'translations' => 'array',
        'forbidden' => 'array',
        'evidence' => 'array',
        'decided_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function glossary(): BelongsTo
    {
        return $this->belongsTo(AiGlossary::class, 'glossary_id');
    }
}
