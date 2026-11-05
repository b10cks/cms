<?php

namespace App\Models\Space;

use App\Enums\AiChangesetItemStatus;
use App\Services\Ai\Changesets\ContentFlags;
use App\Services\Ai\Knowledge\GlossaryChecker;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property list<array<string, mixed>>|null $glossary_findings a finding whose wording left the field carries `cleared`
 * @property int $open_findings flagged wording still in the draft, see {@see ContentFlags}
 * @property array<string, mixed>|null $review group, reason, flags, depends_on, bundle and labels (see AssetReview), policy provenance, the locales a proposal is written in, overrides (see AcceptanceReview)
 * @property string|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $learned_at
 * @property int|null $operation_item_id
 * @property list<array{code: string, message: string}>|null $flags
 */
class AiChangesetItem extends SpaceDatabaseModel
{
    use HasUlids;

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_CREATE = 'create';

    public const TARGET_CONTENT = 'content';

    public const TARGET_DATA_ENTRY = 'data_entry';

    public const TARGET_ASSET = 'asset';

    /** A new asset folder; created on accept. */
    public const TARGET_ASSET_FOLDER = 'asset_folder';

    /** A new asset tag; created on accept. */
    public const TARGET_ASSET_TAG = 'asset_tag';

    /** A block definition. A proposal target only: no bulk scopes, schedules or preflight. */
    public const TARGET_BLOCK = 'block';

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
        'open_findings',
        'review',
        'decided_by_id',
        'decided_at',
        'learned_at',
        'operation_item_id',
        'flags',
    ];

    protected $casts = [
        'before' => 'json',
        'after' => 'json',
        'final' => 'json',
        'current' => 'json',
        'status' => AiChangesetItemStatus::class,
        'glossary_findings' => 'array',
        'open_findings' => 'integer',
        'review' => 'array',
        'decided_at' => 'datetime',
        'learned_at' => 'datetime',
        'operation_item_id' => 'integer',
        'flags' => 'array',
    ];

    public function changeset(): BelongsTo
    {
        return $this->belongsTo(AiChangeset::class, 'changeset_id');
    }

    /**
     * The blocking findings that still stand. On written content that is what is
     * still in the draft; on anything else, the findings as recorded.
     *
     * @return list<array<string, mixed>>
     */
    public function standingFindings(): array
    {
        $blocking = GlossaryChecker::blocking($this->glossary_findings ?? []);

        if ($this->target_type !== self::TARGET_CONTENT || $this->status !== AiChangesetItemStatus::Accepted) {
            return $blocking;
        }

        return $this->open_findings > 0
            ? array_values(array_filter($blocking, fn (array $finding) => ! ($finding['cleared'] ?? false)))
            : [];
    }

    public function isOpen(): bool
    {
        return \in_array($this->status, [AiChangesetItemStatus::Pending, AiChangesetItemStatus::Conflict], true);
    }
}
