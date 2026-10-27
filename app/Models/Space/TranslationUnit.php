<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TranslationUnit extends SpaceModel
{
    use HasUlids;

    public const ORIGIN_HUMAN = 'human';

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
}
