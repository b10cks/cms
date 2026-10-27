<?php

namespace App\Models\Management;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiChangesetStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The proposals of one run: field-level patches pinned to the version the run
 * read. Accepting writes drafts through the existing actions.
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $run_id
 * @property string $actor_id
 * @property string $title
 * @property bool $inline
 * @property AiChangesetStatus $status
 * @property int $item_count
 * @property int $pending_count
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AiChangeset extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['space_id', 'run_id', 'actor_id', 'title', 'inline', 'status', 'item_count', 'pending_count'];

    protected $casts = [
        'inline' => 'boolean',
        'status' => AiChangesetStatus::class,
        'item_count' => 'integer',
        'pending_count' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'run_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiChangesetItem::class, 'changeset_id');
    }

    /** Recount items and derive the status from them. */
    public function refreshCounts(): void
    {
        $counts = $this->items()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $pending = (int) ($counts[AiChangesetItemStatus::Pending->value] ?? 0) + (int) ($counts[AiChangesetItemStatus::Conflict->value] ?? 0);
        $accepted = (int) ($counts[AiChangesetItemStatus::Accepted->value] ?? 0);

        $this->item_count = (int) $counts->sum();
        $this->pending_count = $pending;
        $this->status = match (true) {
            $pending > 0 => $accepted > 0 ? AiChangesetStatus::PartiallyApplied : AiChangesetStatus::Open,
            $accepted > 0 => AiChangesetStatus::Applied,
            default => AiChangesetStatus::Discarded,
        };
        $this->save();
    }
}
