<?php

namespace App\Models\Space;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiChangesetStatus;
use App\Enums\AiOperationState;
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
 * @property list<array{type: string, id: string, label: string}>|null $context
 */
class AiConversation extends SpaceDatabaseModel
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
     * `needs_input`, `needs_review`, `running`, `latest_run_state`,
     * `reasoning_effort` (the latest run's) and `entry_count`.
     */
    public function scopeWithStatus(Builder $query): void
    {
        $query->select('ai_conversations.*');

        foreach (['needs_input' => self::existsSql(self::awaitingInput()), 'needs_review' => self::reviewSql(), 'running' => self::runningSql()] as $alias => [$sql, $bindings]) {
            $query->selectRaw("{$sql} as {$alias}", $bindings);
        }

        $latest = fn (string $column) => AiRun::query()->select($column)
            ->whereColumn('conversation_id', 'ai_conversations.id')
            ->orderByDesc('started_at')->orderByDesc('id')->limit(1);
        $query->addSelect(['latest_run_state' => $latest('state'), 'reasoning_effort' => $latest('reasoning_effort')]);

        // An operation's changeset belongs to the thread through the operation: its batch runs have none.
        $entries = self::table('ai_changeset_items')
            ->selectRaw('count(distinct coalesce('.self::wrap('ai_changeset_items.target_id').', '.self::wrap('ai_changeset_items.id').'))')
            ->join('ai_changesets', 'ai_changesets.id', '=', 'ai_changeset_items.changeset_id')
            ->leftJoin('ai_runs', 'ai_runs.id', '=', 'ai_changesets.run_id')
            ->leftJoin('ai_operations', 'ai_operations.id', '=', 'ai_changesets.operation_id')
            ->where(fn (QueryBuilder $thread) => $thread
                ->where(fn (QueryBuilder $run) => $run->whereNull('ai_changesets.operation_id')->whereColumn('ai_runs.conversation_id', 'ai_conversations.id'))
                ->orWhereColumn('ai_operations.conversation_id', 'ai_conversations.id'))
            ->where('ai_changesets.inline', false)
            ->where(fn (QueryBuilder $open) => $open
                ->whereIn('ai_changeset_items.status', self::OPEN_ITEMS)
                ->orWhere('ai_changeset_items.open_findings', '>', 0));
        $suggestions = self::pendingSuggestions()->selectRaw('count(*)');
        $query->selectRaw(
            '(case when '.self::wrap('ai_conversations.kind').' = ? then ('.$suggestions->toSql().') else ('.$entries->toSql().') end) as entry_count',
            [AiThreadKind::Suggestions->value, ...$suggestions->getBindings(), ...$entries->getBindings()],
        );
    }

    /** Pinned first (waiting for answers, then for review), then by last activity. */
    /** The skill of the thread's first run that used one, as `skill`; the list shows it as the row's context. */
    public function scopeWithFirstSkill(Builder $query): void
    {
        $query->addSelect(['skill' => AiRun::query()
            ->select('skill')
            ->whereColumn('conversation_id', $query->getModel()->qualifyColumn('id'))
            ->whereNotNull('skill')
            ->oldest('created_at')
            ->limit(1)]);
    }

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
        return self::table('ai_runs')->selectRaw('1')
            ->whereColumn('ai_runs.conversation_id', 'ai_conversations.id')
            ->where('ai_runs.state', $state->value);
    }

    /**
     * A running run in the thread, or a running operation: its batches run
     * outside the thread.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function runningSql(): array
    {
        [$runs, $runBindings] = self::existsSql(self::runsInState(AiRunState::Running));
        [$operations, $operationBindings] = self::existsSql(self::table('ai_operations')->selectRaw('1')
            ->whereColumn('ai_operations.conversation_id', 'ai_conversations.id')
            ->where('ai_operations.state', AiOperationState::Running->value));

        return ["({$runs} or {$operations})", [...$runBindings, ...$operationBindings]];
    }

    private static function awaitingInput(): QueryBuilder
    {
        return self::runsInState(AiRunState::AwaitingInput);
    }

    private static function pendingSuggestions(): QueryBuilder
    {
        return self::table('ai_glossary_suggestions')
            ->whereColumn('ai_glossary_suggestions.space_id', 'ai_conversations.space_id')
            ->where('ai_glossary_suggestions.status', AiGlossarySuggestion::STATUS_PENDING);
    }

    /**
     * A background changeset in the thread, its own or that of an operation the
     * thread holds, that is open or still has flagged wording in a draft, or for
     * the suggestions thread a pending suggestion of the space.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function reviewSql(): array
    {
        $open = [AiChangesetStatus::Open->value, AiChangesetStatus::PartiallyApplied->value];
        $needsYou = fn (QueryBuilder $changeset) => $changeset
            ->whereIn('ai_changesets.status', $open)
            ->orWhereExists(self::table('ai_changeset_items')->selectRaw('1')
                ->whereColumn('ai_changeset_items.changeset_id', 'ai_changesets.id')
                ->where('ai_changeset_items.open_findings', '>', 0));
        $changesets = self::table('ai_changesets')->selectRaw('1')
            ->join('ai_runs', 'ai_runs.id', '=', 'ai_changesets.run_id')
            ->whereColumn('ai_runs.conversation_id', 'ai_conversations.id')
            ->whereNull('ai_changesets.operation_id')
            ->where('ai_changesets.inline', false)
            ->where($needsYou);
        $operations = self::table('ai_changesets')->selectRaw('1')
            ->join('ai_operations', 'ai_operations.id', '=', 'ai_changesets.operation_id')
            ->whereColumn('ai_operations.conversation_id', 'ai_conversations.id')
            ->where($needsYou);
        [$suggestions, $suggestionBindings] = self::existsSql(self::pendingSuggestions()->selectRaw('1'));
        [$runReview, $runBindings] = self::existsSql($changesets);
        [$operationReview, $operationBindings] = self::existsSql($operations);

        return [
            '(case when '.self::wrap('ai_conversations.kind')." = ? then {$suggestions} else ({$runReview} or {$operationReview}) end)",
            [AiThreadKind::Suggestions->value, ...$suggestionBindings, ...$runBindings, ...$operationBindings],
        ];
    }

    /** A query on the space connection the threads live on. */
    private static function table(string $table): QueryBuilder
    {
        return (new self)->getConnection()->table($table);
    }

    /** Quote a `table.column` for raw SQL, with the connection's table prefix. */
    private static function wrap(string $column): string
    {
        return (new self)->getConnection()->getQueryGrammar()->wrap($column);
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function existsSql(QueryBuilder $query): array
    {
        return ['(exists ('.$query->toSql().'))', $query->getBindings()];
    }
}
