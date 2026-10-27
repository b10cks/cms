<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Enums\AiRunState;
use App\Events\Ai\AiInboxChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnswerAiRunRequest;
use App\Http\Requests\Ai\StartAiRunRequest;
use App\Http\Resources\Management\Ai\AiRunEventResource;
use App\Http\Resources\Management\Ai\AiRunResource;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiConversation;
use App\Models\Management\AiRun;
use App\Models\Management\Space;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Run\RunBusy;
use App\Services\Ai\Run\RunEvent;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Support\AiSseStream;
use App\Services\Auth\AuthorizationService;
use App\Support\SpaceContext;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts, resumes, follows and cancels agent runs. Inline runs stream SSE;
 * a run that outlives the request continues on the queue and the client
 * follows it with `events?after=`.
 */
class AiRunController extends Controller
{
    public function store(StartAiRunRequest $request, Space $space, AiRunService $runs, SkillResolver $skills, EntryScope $scope): Response
    {
        $skill = null;
        if ($name = $request->input('skill')) {
            $skill = $skills->resolve($space)[$name] ?? throw ValidationException::withMessages(['skill' => ['This skill is not available in the space.']]);
        }

        $conversationId = $request->input('conversation_id');
        if ($conversationId !== null) {
            abort_unless(AiConversation::query()
                ->whereKey($conversationId)
                ->where('space_id', $space->id)
                ->where('user_id', $request->user()->id)
                ->exists(), 404);
        }

        $context = (array) $request->input('context', []);
        $targets = $request->filled('targets') ? $this->targets($request, $space, $scope) : null;
        if ($targets !== null) {
            $context['targets'] = $targets['context'];
        }

        $start = new StartRun(
            space: $space,
            actor: $request->user(),
            surface: $request->string('surface')->value(),
            skill: $skill,
            prompt: (string) $request->input('prompt', ''),
            context: $context,
            config: $request->filled('config_id') ? $space->aiConfigs()->find($request->input('config_id')) : null,
            idempotencyKey: $request->input('idempotency_key'),
            conversationId: $conversationId,
            instructions: $targets !== null ? StartRun::TARGET_INSTRUCTIONS : '',
            targets: $targets['frozen'] ?? null,
        );

        // More entries than an inline request may touch always run in the background.
        $background = $request->boolean('background')
            || ($targets !== null && \count($targets['frozen']['ids']) > (int) config('ai.inline_entry_limit'));

        if ($background) {
            return $this->startInBackground($runs, $start, $space);
        }

        $run = $runs->start($start);

        // A repeated idempotency key returns the run it started.
        if (! $run->wasRecentlyCreated) {
            return response()->json(['data' => new AiRunResource($run)]);
        }

        return $this->stream($runs, $run, $space);
    }

    /**
     * Start a queued run, unless the space already runs its limit of
     * background runs. The lock keeps two concurrent starts from both passing.
     */
    private function startInBackground(AiRunService $runs, StartRun $start, Space $space): Response
    {
        return Cache::lock("ai-background-start:{$space->id}", 10)->block(5, function () use ($runs, $start, $space) {
            $running = AiRun::query()
                ->where('space_id', $space->id)
                ->where('mode', AiRun::MODE_BACKGROUND)
                ->where('state', AiRunState::Running->value)
                ->count();

            if ($running >= (int) config('ai.runs.max_concurrent_background')) {
                return response()->json([
                    'message' => 'This space already runs its limit of background AI tasks. Try again when one has finished.',
                    'reason' => 'concurrency_limit',
                ], 429);
            }

            $run = $runs->start($start);

            if ($run->wasRecentlyCreated) {
                $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
                broadcast(new AiInboxChanged($space->id, runId: $run->id));
                RunAgentJob::dispatch($run->id);
            }

            return response()->json(['data' => new AiRunResource($run)], $run->wasRecentlyCreated ? 202 : 200);
        });
    }

    /**
     * Resolve a confirmed selection the same way the preflight did. Only
     * entries that exist and the actor may read are frozen on the run.
     *
     * @return array{frozen: array{type: string, ids: list<string>}, context: array<string, mixed>}
     */
    private function targets(StartAiRunRequest $request, Space $space, EntryScope $scope): array
    {
        $type = (string) $request->input('targets.type');
        abort_unless(app(AuthorizationService::class)->canInSpace($request->user(), $space, app(ChangeTargets::class)->for($type)->viewAbility()), 403);

        $entries = $scope->resolve($type, ['ids' => (array) $request->input('targets.ids')]);
        if ($entries === []) {
            throw ValidationException::withMessages(['targets.ids' => ['None of the selected entries exist.']]);
        }

        return [
            'frozen' => ['type' => $type, 'ids' => array_column($entries, 'id')],
            'context' => ['type' => $type, 'entries' => array_map(fn (array $entry) => ['id' => $entry['id'], 'name' => $entry['name']], $entries)],
        ];
    }

    public function show(Request $request, Space $space, string $run): AiRunResource
    {
        return new AiRunResource($this->findRun($request, $space, $run));
    }

    public function events(Request $request, Space $space, string $run, AiRunService $runs): JsonResponse
    {
        $model = $this->findRun($request, $space, $run);
        $after = max(0, (int) $request->query('after', 0));

        return response()->json([
            'data' => AiRunEventResource::collection($runs->eventsAfter($model, $after)),
            'run' => new AiRunResource($model),
        ]);
    }

    public function answer(AnswerAiRunRequest $request, Space $space, string $run, AiRunService $runs): Response
    {
        $model = $runs->answer($this->findRun($request, $space, $run), $request->user(), $request->input('answers', []));

        if ($model->mode === AiRun::MODE_BACKGROUND) {
            RunAgentJob::dispatch($model->id);

            return response()->json(['data' => new AiRunResource($model)], 202);
        }

        return $this->stream($runs, $model, $space);
    }

    public function cancel(Request $request, Space $space, string $run, AiRunService $runs): AiRunResource
    {
        $model = $this->findRun($request, $space, $run);
        $runs->cancel($model);

        return new AiRunResource($model->refresh());
    }

    private function findRun(Request $request, Space $space, string $id): AiRun
    {
        $run = AiRun::query()->where('space_id', $space->id)->findOrFail($id);
        $this->authorize('view', [$run, $space]);

        return $run;
    }

    private function stream(AiRunService $runs, AiRun $run, Space $space): Response
    {
        return AiSseStream::response(
            fn () => $this->inline($runs, $run, $space),
            ['endpoint' => 'ai-run', 'space' => $space->id, 'run' => $run->id],
        );
    }

    /**
     * @return Generator<int, RunEvent>
     */
    private function inline(AiRunService $runs, AiRun $run, Space $space): Generator
    {
        $restore = SpaceContext::enter($space);
        $owned = true;

        try {
            yield from $runs->advance($run, microtime(true) + (int) config('ai.runs.inline_seconds'));
        } catch (RunBusy $e) {
            $owned = false;

            throw $e;
        } catch (\Throwable $e) {
            yield from $runs->fail($run, null, 'internal_error', 'Something went wrong while generating.', $e);
        } finally {
            $restore();

            // Nobody follows an inline run once its stream is gone (closed tab).
            $run->refresh();
            if ($owned && $run->state === AiRunState::Running && $run->mode === AiRun::MODE_INLINE) {
                $runs->cancel($run);
            }
        }
    }
}
