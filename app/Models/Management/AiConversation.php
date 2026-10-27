<?php

namespace App\Models\Management;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiChangesetStatus;
use App\Enums\AiRunState;
use App\Enums\AiThreadKind;
use App\Enums\AiThreadStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A thread: one user's chat, a schedule's standing thread or the space's
 * suggestions thread. Each run in it is a turn and appends its prompt and
 * answer as messages. Its status is derived, see {@see scopeWithStatus()}.
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $user_id
 * @property AiThreadKind $kind
 * @property string|null $schedule_id
 * @property string $surface
 * @property string|null $title
 * @property bool $shared
 * @property bool $quiet
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_activity_at
 * @property array<string, mixed>|null $context
 */
class AiConversation extends GlobalModel
{
    use HasUlids;

    private const OPEN_ITEMS = [AiChangesetItemStatus::Pending->value, AiChangesetItemStatus::Conflict->value];

    protected $fillable = ['space_id', 'user_id', 'kind', 'schedule_id', 'surface', 'title', 'shared', 'quiet', 'archived_at', 'last_activity_at', 'context'];

    protected $attributes = ['kind' => 'chat', 'shared' => false, 'quiet' => false];

    protected $casts = [
        'kind' => AiThreadKind::class,
        'shared' => 'boolean',
        'quiet' => 'boolean',
        'archived_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'context' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'conversation_id');
    }

    /**
     * Threads of the space the user can see: their own chats and shared ones,
     * schedule and suggestion threads with `ai.manage`.
     */
    public function scopeVisibleTo(Builder $query, User $user, bool $managesAi): void
    {
        $query->where(fn (Builder $visible) => $visible
            ->where(fn (Builder $chat) => $chat
                ->where('kind', AiThreadKind::Chat->value)
                ->where(fn (Builder $mine) => $mine->where('user_id', $user->id)->orWhere('shared', true)))
            ->when($managesAi, fn (Builder $standing) => $standing->orWhereIn('kind', [AiThreadKind::Schedule->value, AiThreadKind::Suggestions->value])));
    }

    /**
     * Select what {@see status()} and the list need, as subqueries per row:
     * `needs_input`, `needs_review`, `running`, `latest_run_state` and `entry_count`.
     */
    public function scopeWithStatus(Builder $query): void
    {
        $query->select('ai_conversations.*');

        foreach (['needs_input' => self::awaitingInput(), 'needs_review' => null, 'running' => self::runsInState(AiRunState::Running)] as $alias => $sub) {
            [$sql, $bindings] = $sub !== null ? self::existsSql($sub) : self::reviewSql();
            $query->selectRaw("{$sql} as {$alias}", $bindings);
        }

        $query->addSelect([
            'latest_run_state' => AiRun::query()->select('state')
                ->whereColumn('conversation_id', 'ai_conversations.id')
                ->orderByDesc('started_at')->orderByDesc('id')->limit(1),
        ]);

        $entries = DB::table('ai_changeset_items')
            ->selectRaw('count(distinct coalesce(ai_changeset_items.target_id, ai_changeset_items.id))')
            ->join('ai_changesets', 'ai_changesets.id', '=', 'ai_changeset_items.changeset_id')
            ->join('ai_runs', 'ai_runs.id', '=', 'ai_changesets.run_id')
            ->whereColumn('ai_runs.conversation_id', 'ai_conversations.id')
            ->where('ai_changesets.inline', false)
            ->whereIn('ai_changeset_items.status', self::OPEN_ITEMS);
        $suggestions = self::pendingSuggestions()->selectRaw('count(*)');
        $query->selectRaw(
            '(case when ai_conversations.kind = ? then ('.$suggestions->toSql().') else ('.$entries->toSql().') end) as entry_count',
            [AiThreadKind::Suggestions->value, ...$suggestions->getBindings(), ...$entries->getBindings()],
        );
    }

    /** Pinned first (waiting for answers, then for review), then by last activity. */
    public function scopeNeedsYouFirst(Builder $query): void
    {
        $query->orderByDesc('needs_input')->orderByDesc('needs_review')->orderByDesc('last_activity_at')->orderByDesc('id');
    }

    /** Threads waiting for an answer or a review, without the status selects. */
    public function scopeNeedingYou(Builder $query): void
    {
        [$review, $bindings] = self::reviewSql();
        $query->where(fn (Builder $needs) => $needs->whereExists(self::awaitingInput())->orWhereRaw($review, $bindings));
    }

    /** Requires {@see scopeWithStatus()}. */
    public function status(): AiThreadStatus
    {
        return match (true) {
            (bool) $this->getAttribute('needs_input') => AiThreadStatus::NeedsInput,
            (bool) $this->getAttribute('needs_review') => AiThreadStatus::NeedsReview,
            (bool) $this->getAttribute('running') => AiThreadStatus::Running,
            $this->getAttribute('latest_run_state') === AiRunState::Failed->value => AiThreadStatus::Failed,
            default => AiThreadStatus::Done,
        };
    }

    private static function runsInState(AiRunState $state): QueryBuilder
    {
        return DB::table('ai_runs')->select(DB::raw(1))
            ->whereColumn('ai_runs.conversation_id', 'ai_conversations.id')
            ->where('ai_runs.state', $state->value);
    }

    private static function awaitingInput(): QueryBuilder
    {
        return self::runsInState(AiRunState::AwaitingInput);
    }

    private static function pendingSuggestions(): QueryBuilder
    {
        return DB::table('ai_glossary_suggestions')
            ->whereColumn('ai_glossary_suggestions.space_id', 'ai_conversations.space_id')
            ->where('ai_glossary_suggestions.status', AiGlossarySuggestion::STATUS_PENDING);
    }

    /**
     * An open background changeset in the thread, or for the suggestions
     * thread a pending suggestion of the space.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function reviewSql(): array
    {
        $changesets = DB::table('ai_changesets')->select(DB::raw(1))
            ->join('ai_runs', 'ai_runs.id', '=', 'ai_changesets.run_id')
            ->whereColumn('ai_runs.conversation_id', 'ai_conversations.id')
            ->where('ai_changesets.inline', false)
            ->whereIn('ai_changesets.status', [AiChangesetStatus::Open->value, AiChangesetStatus::PartiallyApplied->value]);
        [$suggestions, $suggestionBindings] = self::existsSql(self::pendingSuggestions()->select(DB::raw(1)));
        [$open, $openBindings] = self::existsSql($changesets);

        return [
            "(case when ai_conversations.kind = ? then {$suggestions} else {$open} end)",
            [AiThreadKind::Suggestions->value, ...$suggestionBindings, ...$openBindings],
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function existsSql(QueryBuilder $query): array
    {
        return ['(exists ('.$query->toSql().'))', $query->getBindings()];
    }
}
