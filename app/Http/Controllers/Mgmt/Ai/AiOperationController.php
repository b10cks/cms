<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationKind;
use App\Enums\AiRunState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ApproveAiOperationRequest;
use App\Http\Requests\Ai\DecideAiOperationGroupRequest;
use App\Http\Requests\Ai\ExtendAiOperationBudgetRequest;
use App\Http\Requests\Ai\StartAiOperationRequest;
use App\Http\Requests\Ai\UpdateAiOperationRequest;
use App\Http\Resources\Management\Ai\AiChangesetItemResource;
use App\Http\Resources\Management\Ai\AiOperationItemResource;
use App\Http\Resources\Management\Ai\AiOperationResource;
use App\Http\Resources\Management\Ai\AiRunResource;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiConversation;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Operations\CreateOperation;
use App\Services\Ai\Operations\OperationReview;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Translation\TranslationLineage;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AI content operations: freeze a scope, draft and approve a plan, follow
 * the batches, steer them (pause, resume, budget, cancel) and review the
 * proposals in groups. Individual items are decided through the changeset
 * endpoints with the operation's `changeset_id`.
 */
class AiOperationController extends Controller
{
    /**
     * The user's operations and, with `ai.manage`, those schedules started, as AiOperationPolicy::view() allows. At most 50, newest first.
     *
     * @apiQuery conversation_id string
     */
    public function index(Request $request, Space $space, AuthorizationService $auth): AnonymousResourceCollection
    {
        $managesAi = $auth->canInSpace($request->user(), $space, 'ai.manage');
        $operations = AiOperation::query()
            ->where('space_id', $space->id)
            ->where(fn ($visible) => $visible
                ->where('actor_id', $request->user()->id)
                ->when($managesAi, fn ($scheduled) => $scheduled->orWhereNotNull('schedule_id')))
            ->when($request->filled('conversation_id'), fn ($query) => $query->where('conversation_id', $request->query('conversation_id')))
            ->with(['currentRun', 'changeset'])
            ->latest()
            ->limit(50)
            ->get();

        return AiOperationResource::collection($operations);
    }

    /**
     * Create a draft operation and freeze its scope. Starts no provider call.
     *
     * @apiResponse 201 data:@AiOperationResource
     * @apiResponse 422 @AiScopeTooLarge An empty scope is a validation error with `errors.scope`.
     */
    public function store(StartAiOperationRequest $request, Space $space, AiOperations $operations, SkillResolver $skills, ChangeTargets $targets, AuthorizationService $auth): JsonResponse
    {
        abort_unless($auth->canInSpace($request->user(), $space, $targets->for($request->targetType())->viewAbility()), 403);

        $skill = null;
        if ($name = $request->input('skill')) {
            $skill = $skills->resolve($space)[$name] ?? throw ValidationException::withMessages(['skill' => ['This skill is not available in the space.']]);
        }

        $conversationId = $request->input('conversation_id');
        if ($conversationId !== null) {
            abort_unless(AiConversation::query()->whereKey($conversationId)->where('space_id', $space->id)->where('user_id', $request->user()->id)->exists(), 404);
        }

        $operation = $operations->create(new CreateOperation(
            space: $space,
            actor: $request->user(),
            kind: $request->enum('kind', AiOperationKind::class),
            prompt: (string) $request->input('prompt'),
            scope: (array) $request->input('scope'),
            skill: $skill,
            config: $request->filled('config_id') ? $space->aiConfigs()->find($request->input('config_id')) : null,
            conversationId: $conversationId,
        ));

        return (new AiOperationResource($operation))->response()->setStatusCode(201);
    }

    /**
     * Get an operation with its plan, budget, policy snapshot, progress and stored coverage.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 403 @Error
     * @apiResponse 404 @Error Unknown, or owned by another space.
     */
    public function show(Request $request, Space $space, string $operation): AiOperationResource
    {
        return new AiOperationResource($this->find($space, $operation, 'view')->load(['currentRun', 'changeset']));
    }

    /**
     * Save the edited draft plan. Starts no provider call.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict The plan is already approved.
     * @apiResponse 422 @ValidationError `errors.plan.<key>` for each plan problem.
     */
    public function update(UpdateAiOperationRequest $request, Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        return new AiOperationResource($operations->savePlan($this->find($space, $operation), (array) $request->input('plan')));
    }

    /**
     * Start the planning run, which is paid provider work. The client follows it through the run events.
     *
     * @apiResponse 202 data:@AiRunResource
     * @apiResponse 409 @AiOperationConflict
     * @apiResponse 429 @AiConcurrencyLimit
     */
    public function plan(Space $space, string $operation, AiOperations $operations): JsonResponse
    {
        $this->ensureCapacity($space);
        $run = $operations->startPlanning($this->find($space, $operation));

        return response()->json(['data' => new AiRunResource($run)], 202);
    }

    /**
     * Approve the reviewed plan and budget, which starts paid batches and pins the policy snapshot.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict `plan_changed` or `scope_changed`: review again.
     * @apiResponse 422 @ValidationError
     * @apiResponse 429 @AiConcurrencyLimit
     */
    public function approve(ApproveAiOperationRequest $request, Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        $this->ensureCapacity($space);

        return new AiOperationResource($operations->approve(
            $this->find($space, $operation, 'approve'),
            $request->user(),
            (string) $request->input('plan_hash'),
            (float) $request->input('max_usd'),
            (string) $request->input('scope_hash'),
        ));
    }

    /**
     * Freeze a draft's scope again after approval answered `scope_changed`. Starts no provider call.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict
     */
    public function refreshScope(Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        return new AiOperationResource($operations->refreshScope($this->find($space, $operation)));
    }

    /**
     * Stop starting batches. A running batch finishes.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict
     */
    public function pause(Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        return new AiOperationResource($operations->pause($this->find($space, $operation)));
    }

    /**
     * Resume a paused operation, which starts paid batches again within the budget. A spent budget answers 409 `budget`.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict
     * @apiResponse 429 @AiConcurrencyLimit
     */
    public function resume(Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        $model = $this->find($space, $operation);
        if ($model->current_run_id === null) {
            $this->ensureCapacity($space);
        }

        return new AiOperationResource($operations->resume($model));
    }

    /**
     * Cancel an unfinished operation. Proposals stay reviewable.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict
     */
    public function cancel(Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        return new AiOperationResource($operations->cancel($this->find($space, $operation)));
    }

    /**
     * Set a new total budget, above what was spent. Only this explicit call raises a budget. On a budget pause it also resumes, which starts paid batches.
     *
     * @apiResponse 200 data:@AiOperationResource
     * @apiResponse 409 @AiOperationConflict
     * @apiResponse 422 @ValidationError
     */
    public function budget(ExtendAiOperationBudgetRequest $request, Space $space, string $operation, AiOperations $operations): AiOperationResource
    {
        return new AiOperationResource($operations->extendBudget($this->find($space, $operation), (float) $request->input('max_usd')));
    }

    /**
     * The ledger, filterable by status and reason. `parts` holds the outcome of each requested output.
     *
     * @apiQuery status enum:pending|running|proposed|unchanged|skipped|failed
     * @apiQuery reason string
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     */
    public function items(Request $request, Space $space, string $operation): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(AiOperationItemStatus::class)],
            'reason' => ['sometimes', 'string', 'max:60'],
        ]);
        $model = $this->find($space, $operation, 'view');

        return AiOperationItemResource::collection($model->items()
            ->withCount('proposals')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->query('reason')))
            ->orderBy('position')
            ->paginate($this->perPage($request, 50, 200)));
    }

    /**
     * Entries that match the scope now but were not frozen into it.
     *
     * @apiResponse 200 data:@AiOperationArrivals
     */
    public function arrivals(Space $space, string $operation, AiOperations $operations): JsonResponse
    {
        return response()->json(['data' => $operations->arrivals($this->find($space, $operation, 'view'))]);
    }

    /**
     * Proposals for assets, data entries and blocks by review group, with counts and examples. Content is written to drafts instead: see changes.
     *
     * @apiResponse 200 data:@AiOperationReviewGroup[]
     */
    public function review(Space $space, string $operation, OperationReview $review): JsonResponse
    {
        return response()->json(['data' => $review->groups($this->find($space, $operation, 'view'))]);
    }

    /**
     * Items of one review group, or of one changed entry. Translation items carry their source deltas as `translation_units`.
     *
     * @apiQuery group string A `key` from the review groups. Required without `target_id`.
     * @apiQuery target_id string An entry from changes: its content items, conflicts first.
     * @apiQuery individual boolean Only items of the group that need an individual decision.
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 @AiOperationReviewItems
     */
    public function reviewItems(Request $request, Space $space, string $operation, OperationReview $review, TranslationLineage $lineage): JsonResponse
    {
        $request->validate(['group' => ['required_without:target_id', 'string', 'max:700'], 'target_id' => ['sometimes', 'string', 'max:64'], 'individual' => ['sometimes', 'boolean']]);
        $perPage = $this->perPage($request, 50, 200);
        $page = max(1, (int) $request->query('page', 1));
        $model = $this->find($space, $operation, 'view');
        $result = $request->filled('target_id')
            ? $review->entryItems($model, (string) $request->query('target_id'), $page, $perPage)
            : $review->items($model, (string) $request->query('group'), $page, $perPage, $request->boolean('individual'));
        $lineage = $lineage->forItems($result['items']->modelKeys());

        return response()->json([
            'data' => $result['items']->map(fn (AiChangesetItem $item) => new AiChangesetItemResource($item, $lineage[$item->id] ?? []))->values(),
            'meta' => ['total' => $result['total'], 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    /**
     * What the operation changed in drafts, an entry at a time, entries with conflicts first. Nothing here needs a decision except a conflict.
     *
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 @AiOperationChanges
     */
    public function changes(Request $request, Space $space, string $operation, OperationReview $review): JsonResponse
    {
        $perPage = $this->perPage($request, 25, 200);
        $page = max(1, (int) $request->query('page', 1));
        $result = $review->changes($this->find($space, $operation, 'view'), $page, $perPage);

        return response()->json([
            'data' => $result['entries'],
            'summary' => $result['summary'],
            'meta' => ['total' => $result['total'], 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    /**
     * Accept a group of proposals. 202 when a large group went to the queue. Content is never in a group: it is already in its draft.
     *
     * Flagged, limited and edited items stay open for an individual decision.
     *
     * @apiResponse 200 data:@AiOperationGroupAccepted
     * @apiResponse 202 data:@AiOperationGroupAccepted
     */
    public function acceptGroup(DecideAiOperationGroupRequest $request, Space $space, string $operation, OperationReview $review): JsonResponse
    {
        $model = $this->find($space, $operation);
        $result = $review->acceptGroup($model, $space, $request->user(), (string) $request->input('group'));

        return response()->json(['data' => $result], $result['queued'] > 0 ? 202 : 200);
    }

    /**
     * Reject a group's open proposals. 202 when a large group went to the queue. Content is never in a group.
     *
     * @apiResponse 200 data:@AiOperationGroupRejected
     * @apiResponse 202 data:@AiOperationGroupRejected
     */
    public function rejectGroup(DecideAiOperationGroupRequest $request, Space $space, string $operation, OperationReview $review): JsonResponse
    {
        $result = $review->rejectGroup($this->find($space, $operation), $space, $request->user(), (string) $request->input('group'));

        return response()->json(['data' => $result], $result['queued'] > 0 ? 202 : 200);
    }

    private function find(Space $space, string $id, string $ability = 'update'): AiOperation
    {
        $operation = AiOperation::query()->where('space_id', $space->id)->findOrFail($id);
        $this->authorize($ability, [$operation, $space]);

        return $operation;
    }

    /** Batches are background runs and share the space's limit; a running operation keeps its slot. */
    private function ensureCapacity(Space $space): void
    {
        $running = AiRun::query()
            ->where('space_id', $space->id)
            ->where('mode', AiRun::MODE_BACKGROUND)
            ->where('state', AiRunState::Running->value)
            ->count();

        abort_if($running >= (int) config('ai.runs.max_concurrent_background'), response()->json([
            'message' => 'This space already runs its limit of background AI tasks. Try again when one has finished.',
            'reason' => 'concurrency_limit',
        ], 429));
    }
}
