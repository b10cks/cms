<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Ai\AcceptAiGlossarySuggestion;
use App\Enums\AiThreadKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UpdateAiThreadRequest;
use App\Http\Resources\Management\Ai\AiAttachmentResource;
use App\Http\Resources\Management\Ai\AiChangesetResource;
use App\Http\Resources\Management\Ai\AiGlossaryResource;
use App\Http\Resources\Management\Ai\AiGlossarySuggestionResource;
use App\Http\Resources\Management\Ai\AiRunResource;
use App\Http\Resources\Management\Ai\AiThreadResource;
use App\Models\Management\Space;
use App\Models\Space\AiAttachment;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiConversation;
use App\Models\Space\AiGlossarySuggestion;
use App\Models\Space\AiMessage;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The threads a member can see: their own chats, chats shared with the
 * space, and with `ai.manage` the schedule and suggestion threads. Status is
 * derived per row in SQL, see {@see AiConversation::scopeWithStatus()}.
 */
class AiThreadController extends Controller
{
    private const LIST_LIMIT = 100;

    public function index(Request $request, Space $space, AuthorizationService $auth): AnonymousResourceCollection
    {
        $this->authorizeSpace($space, 'space.view');
        $filters = $request->validate([
            'archived' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $managesAi = $auth->canInSpace($user, $space, 'ai.manage');
        $visible = fn () => AiConversation::query()->where('space_id', $space->id)->visibleTo($user, $managesAi);
        $search = trim((string) ($filters['search'] ?? ''));

        $threads = $visible()
            ->withStatus()
            ->withFirstSkill()
            ->with('owner')
            ->when((bool) ($filters['archived'] ?? false), fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->needsYouFirst()
            ->limit(self::LIST_LIMIT)
            ->get();

        $attention = $visible()->whereNull('archived_at')->where('quiet', false)->needingYou()->count();

        $settled = $visible()->whereNotNull('archived_at')->count();

        return AiThreadResource::collection($threads)->additional(['meta' => ['attention' => $attention, 'settled' => $settled]]);
    }

    public function show(Request $request, Space $space, string $thread, ChangeTargets $targets, AuthorizationService $auth, AcceptAiGlossarySuggestion $suggestions): JsonResponse
    {
        $model = $this->findThread($request, $space, $thread);
        $data = [...(new AiThreadResource($model))->resolve($request), 'context' => $model->context];

        if ($model->kind === AiThreadKind::Suggestions) {
            $pending = AiGlossarySuggestion::query()->where('space_id', $space->id)->where('status', AiGlossarySuggestion::STATUS_PENDING)->with('actor')->latest()->limit(self::LIST_LIMIT)->get();
            $data['suggestions'] = AiGlossarySuggestionResource::collection($pending);
            $data['glossaries'] = AiGlossaryResource::collection($pending->isNotEmpty() ? $suggestions->glossariesFor($space, $request->user()) : collect());
        } else {
            $data['turns'] = $this->turns($model, $space, $request->user(), $targets, $auth);
        }

        return response()->json(['data' => $data]);
    }

    public function update(UpdateAiThreadRequest $request, Space $space, string $thread, AiThreads $threads): AiThreadResource
    {
        $model = $this->findThread($request, $space, $thread);

        if ($request->hasAny(['title', 'shared'])) {
            $this->authorize('update', [$model, $space]);
        }
        if ($request->has('archived')) {
            $this->authorize('archive', [$model, $space]);
            $model->archived_at = $request->boolean('archived') ? ($model->archived_at ?? now()) : null;
        }

        $model->fill($request->safe()->only(['title', 'shared']))->save();
        $threads->changed($space->id, $model->id, activity: false);

        return new AiThreadResource($this->findThread($request, $space, $thread));
    }

    /**
     * One turn per run in start order, with its prompt, answer and changeset.
     * Changeset items are limited to target types the user may view.
     *
     * @return list<array<string, mixed>>
     */
    private function turns(AiConversation $thread, Space $space, User $user, ChangeTargets $targets, AuthorizationService $auth): array
    {
        $runs = AiRun::query()->where('conversation_id', $thread->id)->orderBy('started_at')->orderBy('id')->get();
        $messages = AiMessage::query()->where('conversation_id', $thread->id)->whereIn('run_id', $runs->modelKeys())->get()->groupBy('run_id');
        $attachments = AiAttachment::query()->whereIn('message_id', $messages->flatten(1)->pluck('id'))->orderBy('id')->get()->groupBy('message_id');
        $visible = array_filter(ChangeTargets::PROPOSAL_TYPES, fn (string $type) => $auth->canInSpace($user, $space, $targets->for($type)->viewAbility()));
        $changesets = AiChangeset::query()
            ->whereIn('run_id', $runs->modelKeys())
            ->with(['actor', 'items' => fn ($query) => $query
                ->whereIn('target_type', [...$visible, AiChangesetItem::TARGET_SELECTION])
                ->orderBy('target_label')->orderBy('created_at')])
            ->get()
            ->keyBy('run_id');

        return $runs->map(function (AiRun $run) use ($messages, $changesets, $attachments) {
            $question = $messages->get($run->id)?->firstWhere('role', 'user');
            $changeset = $changesets->get($run->id)?->setRelation('run', $run);

            return [
                'run' => new AiRunResource($run),
                'prompt' => $question?->content,
                'attachments' => AiAttachmentResource::collection($attachments->get($question?->id, collect())),
                'answer' => $messages->get($run->id)?->firstWhere('role', 'assistant')?->content,
                'changeset' => $changeset ? new AiChangesetResource($changeset) : null,
            ];
        })->all();
    }

    /** A thread the user may not see is a 404, so private threads do not leak. */
    private function findThread(Request $request, Space $space, string $id): AiConversation
    {
        $thread = AiConversation::query()->where('space_id', $space->id)->withStatus()->with('owner')->findOrFail($id);
        abort_unless($request->user()->can('view', [$thread, $space]), 404);

        return $thread;
    }
}
