<?php

namespace App\Models\Space;

use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationKind;
use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An approved plan applied to a frozen scope in bounded batch runs. The scope
 * is frozen into {@see AiOperationItem} rows when the operation is created;
 * the plan is frozen at approval. All batch runs write into one changeset.
 *
 * @property string $id
 * @property string $space_id
 * @property string $actor_id
 * @property string|null $conversation_id
 * @property string|null $schedule_id
 * @property AiOperationKind $kind
 * @property string|null $skill
 * @property array{name: string, source: string, owner_id: string|null, skill_id: string|null, revision_id: string|null}|null $skill_ref
 * @property string $surface
 * @property string|null $ai_config_id
 * @property string $prompt
 * @property array<string, mixed> $scope
 * @property array<string, mixed> $counts
 * @property array<string, mixed>|null $plan
 * @property string|null $plan_run_id
 * @property array<string, mixed>|null $approved_plan
 * @property string|null $approved_plan_hash
 * @property string|null $approved_by
 * @property string|null $policy_snapshot_id policy every batch uses, captured at approval; null if approved before snapshots
 * @property Carbon|null $approved_at
 * @property AiOperationState $state
 * @property AiOperationPauseReason|null $pause_reason
 * @property bool $pause_requested
 * @property string|null $error
 * @property float $max_usd
 * @property float $spent_usd
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $batch_count
 * @property string|null $current_run_id
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiOperation extends SpaceDatabaseModel
{
    use HasUlids;

    protected $fillable = [
        'space_id',
        'actor_id',
        'conversation_id',
        'schedule_id',
        'kind',
        'skill',
        'skill_ref',
        'surface',
        'ai_config_id',
        'prompt',
        'scope',
        'counts',
        'plan',
        'plan_run_id',
        'approved_plan',
        'approved_plan_hash',
        'approved_by',
        'approved_at',
        'state',
        'pause_reason',
        'pause_requested',
        'error',
        'max_usd',
        'spent_usd',
        'input_tokens',
        'output_tokens',
        'batch_count',
        'current_run_id',
        'finished_at',
    ];

    protected $casts = [
        'kind' => AiOperationKind::class,
        'skill_ref' => 'array',
        'scope' => 'array',
        'counts' => 'array',
        'plan' => 'array',
        'approved_plan' => 'array',
        'approved_at' => 'datetime',
        'state' => AiOperationState::class,
        'pause_reason' => AiOperationPauseReason::class,
        'pause_requested' => 'boolean',
        'max_usd' => 'float',
        'spent_usd' => 'float',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'batch_count' => 'integer',
        'finished_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiOperationItem::class, 'operation_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'operation_id');
    }

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'current_run_id');
    }

    public function changeset(): HasOne
    {
        return $this->hasOne(AiChangeset::class, 'operation_id');
    }

    /**
     * The parts each entry is done in, or null when an entry is one piece:
     * a translation entry is done once each approved locale has an outcome.
     *
     * @return list<string>|null
     */
    public function expectedParts(): ?array
    {
        return $this->kind === AiOperationKind::Translation
            ? array_values((array) ($this->approved_plan['locales'] ?? []))
            : null;
    }

    public function remainingUsd(): float
    {
        return round(max(0.0, $this->max_usd - $this->spent_usd), 6);
    }

    /**
     * Item counts by status, every status present.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = $this->items()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(AiOperationItemStatus::cases())
            ->mapWithKeys(fn ($status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }
}
