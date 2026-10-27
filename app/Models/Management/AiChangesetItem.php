<?php

namespace App\Models\Management;

use App\Enums\AiChangesetItemStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proposed change. `update` items patch one field (`path`, block IDs never
 * indexes) of an existing target; `create` items carry a whole new entry in
 * `after`. `current` holds the value found when an accept hit a conflict,
 * `final` the value a reviewer accepted after editing the proposal.
 *
 * @property string $id
 * @property string $changeset_id
 * @property string $operation
 * @property string $target_type
 * @property string|null $target_id
 * @property string|null $target_label
 * @property string|null $base_version_id
 * @property string|null $path
 * @property string|null $field_type
 * @property mixed $before
 * @property mixed $after
 * @property mixed $final
 * @property mixed $current
 * @property AiChangesetItemStatus $status
 * @property list<array<string, string>>|null $glossary_findings
 * @property string|null $decided_by_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $learned_at
 */
class AiChangesetItem extends GlobalModel
{
    use HasUlids;

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_CREATE = 'create';

    public const TARGET_CONTENT = 'content';

    public const TARGET_DATA_ENTRY = 'data_entry';

    public const TARGET_ASSET = 'asset';

    /** A rich text selection, applied in the open editor that owns the working copy. */
    public const TARGET_SELECTION = 'selection';

    protected $fillable = [
        'changeset_id',
        'operation',
        'target_type',
        'target_id',
        'target_label',
        'base_version_id',
        'path',
        'field_type',
        'before',
        'after',
        'final',
        'current',
        'status',
        'glossary_findings',
        'decided_by_id',
        'decided_at',
        'learned_at',
    ];

    protected $casts = [
        'before' => 'json',
        'after' => 'json',
        'final' => 'json',
        'current' => 'json',
        'status' => AiChangesetItemStatus::class,
        'glossary_findings' => 'array',
        'decided_at' => 'datetime',
        'learned_at' => 'datetime',
    ];

    public function changeset(): BelongsTo
    {
        return $this->belongsTo(AiChangeset::class, 'changeset_id');
    }

    public function isOpen(): bool
    {
        return \in_array($this->status, [AiChangesetItemStatus::Pending, AiChangesetItemStatus::Conflict], true);
    }
}
