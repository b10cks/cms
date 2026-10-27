<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Ai\AcceptAiChangesetItems;
use App\Actions\Ai\AcceptAiGlossarySuggestion;
use App\Actions\Ai\RejectAiChangesetItems;
use App\Enums\AiChangesetStatus;
use App\Enums\AiRunState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\DecideAiChangesetRequest;
use App\Http\Resources\Management\Ai\AiChangesetResource;
use App\Http\Resources\Management\Ai\AiGlossaryResource;
use App\Http\Resources\Management\Ai\AiGlossarySuggestionResource;
use App\Http\Resources\Management\Ai\AiRunResource;
use App\Models\Management\AiChangeset;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiGlossarySuggestion;
use App\Models\Management\AiRun;
use App\Models\Management\Space;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The space's AI inbox and changeset review. Background changesets are
 * listed for the space; runs only for their actor; glossary suggestions for
 * those who may manage the space's AI.
 */
class AiChangesetController extends Controller
{
    private const OPEN = [AiChangesetStatus::Open, AiChangesetStatus::PartiallyApplied];

    public function inbox(Request $request, Space $space, AuthorizationService $auth, AcceptAiGlossarySuggestion $suggestions): JsonResponse
    {
        $this->authorizeSpace($space, 'space.view');
        $managesAi = $auth->canInSpace($request->user(), $space, 'ai.manage');

        $changesets = AiChangeset::query()
            ->where('space_id', $space->id)
            ->where('inline', false)
            ->whereIn('status', array_map(fn (AiChangesetStatus $status) => $status->value, self::OPEN));

        $runs = AiRun::query()
            ->where('space_id', $space->id)
            ->where('actor_id', $request->user()->id)
            ->where('mode', AiRun::MODE_BACKGROUND)
            ->where(fn ($query) => $query
                ->whereIn('state', [AiRunState::Running->value, AiRunState::AwaitingInput->value])
                ->orWhere(fn ($failed) => $failed->where('state', AiRunState::Failed->value)->where('finished_at', '>=', now()->subDays(7))))
            ->latest('started_at')
            ->limit(50)
            ->get();

        $pending = $managesAi
            ? AiGlossarySuggestion::query()->where('space_id', $space->id)->where('status', AiGlossarySuggestion::STATUS_PENDING)->with('actor')->latest()->limit(100)->get()
            : collect();

        return response()->json([
            'changesets' => AiChangesetResource::collection((clone $changesets)->with(['run', 'actor'])->latest()->limit(100)->get()),
            'runs' => AiRunResource::collection($runs),
            'suggestions' => AiGlossarySuggestionResource::collection($pending),
            'glossaries' => AiGlossaryResource::collection($pending->isNotEmpty() ? $suggestions->glossariesFor($space, $request->user()) : collect()),
            'count' => $changesets->count() + $runs->where('state', AiRunState::AwaitingInput)->count() + $pending->count(),
        ]);
    }

    public function show(Request $request, Space $space, string $changeset, ChangeTargets $targets, AuthorizationService $auth): AiChangesetResource
    {
        $model = $this->findChangeset($space, $changeset);
        $visible = array_filter(
            ChangeTargets::TYPES,
            fn (string $type) => $auth->canInSpace($request->user(), $space, $targets->for($type)->viewAbility()),
        );

        $model->load(['run', 'actor', 'items' => fn ($query) => $query
            ->whereIn('target_type', [...$visible, AiChangesetItem::TARGET_SELECTION])
            ->orderBy('target_label')->orderBy('created_at')]);

        return new AiChangesetResource($model);
    }

    public function accept(DecideAiChangesetRequest $request, Space $space, string $changeset, AcceptAiChangesetItems $action): JsonResponse
    {
        $model = $this->findChangeset($space, $changeset);
        $result = $action->handle($model, $space, $request->user(), $request->itemIds(), $request->boolean('overwrite'), $request->editedValues());

        return response()->json(['data' => new AiChangesetResource($model->refresh()), ...$result]);
    }

    public function reject(DecideAiChangesetRequest $request, Space $space, string $changeset, RejectAiChangesetItems $action): JsonResponse
    {
        $model = $this->findChangeset($space, $changeset);
        $rejected = $action->handle($model, $space, $request->user(), $request->itemIds());

        return response()->json(['data' => new AiChangesetResource($model->refresh()), 'rejected' => $rejected]);
    }

    private function findChangeset(Space $space, string $id): AiChangeset
    {
        $changeset = AiChangeset::query()->where('space_id', $space->id)->findOrFail($id);
        $this->authorize('view', [$changeset, $space]);

        return $changeset;
    }
}
