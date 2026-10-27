<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Ai\AcceptAiChangesetItems;
use App\Actions\Ai\RejectAiChangesetItems;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\DecideAiChangesetRequest;
use App\Http\Resources\Management\Ai\AiChangesetResource;
use App\Models\Management\AiChangeset;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\Space;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Changeset review: one changeset with the items the user may view, and
 * accept or reject per item. Threads list them, see {@see AiThreadController}.
 */
class AiChangesetController extends Controller
{
    public function show(Request $request, Space $space, string $changeset, ChangeTargets $targets, AuthorizationService $auth): AiChangesetResource
    {
        $model = $this->findChangeset($space, $changeset);
        $visible = array_filter(
            ChangeTargets::PROPOSAL_TYPES,
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
        // The accepted values are not an edit of the requesting tab: its open
        // editor must receive the `content:updated` broadcast like everyone else.
        // `toOthers()` reads the socket from the container's request, not from
        // this form request copy.
        request()->headers->remove('X-Socket-ID');
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
