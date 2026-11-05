<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Carbon;

/**
 * The source text one translated value was written against. Lineage of a
 * translation field: the field is stale once the source text differs.
 *
 * @property string $id
 * @property string|null $content_id
 * @property string|null $changeset_item_id
 * @property string $source_content_id
 * @property string $field_path
 * @property string|null $source_version_id
 * @property string $source_hash
 * @property string $source_text
 * @property string $target_hash
 * @property string $origin
 * @property string|null $reason
 * @property string|null $previous_source_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TranslationSource extends SpaceDatabaseModel
{
    use HasUlids;

    /** Written with an AI translation. */
    public const ORIGIN_AI = 'ai';

    /** A reviewer's edit of an AI proposal: the proposal's source, the edited text. */
    public const ORIGIN_REVIEW = 'review';

    /** Derived from the translation's version history. */
    public const ORIGIN_HISTORY = 'history';

    protected $table = 'translation_sources';

    protected $fillable = [
        'content_id',
        'changeset_item_id',
        'source_content_id',
        'field_path',
        'source_version_id',
        'source_hash',
        'source_text',
        'target_hash',
        'origin',
        'reason',
        'previous_source_text',
    ];
}
