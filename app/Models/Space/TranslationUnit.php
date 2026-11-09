<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A translated field of a published translation, the space's translation memory.
 *
 * @property string $id
 * @property string $source_hash
 * @property string $source_locale
 * @property string $target_locale
 * @property string $source_text
 * @property string $target_text
 * @property string $origin
 * @property string $content_id
 * @property string $field_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Content|null $content
 */
class TranslationUnit extends SpaceModel
{
    use HasUlids;

    public const ORIGIN_HUMAN = 'human';

    /** AI text a person published. */
    public const ORIGIN_AI_ACCEPTED = 'ai_accepted';

    protected $table = 'translation_units';

    protected $fillable = [
        'source_hash',
        'source_locale',
        'target_locale',
        'source_text',
        'target_text',
        'origin',
        'content_id',
        'field_path',
    ];

    /** The translation entry the unit was published in, trashed ones included. */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id')->withTrashed();
    }
}
