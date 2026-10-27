<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Enums\AiRunState;
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
use App\Services\Ai\Run\RunBusy;
use App\Services\Ai\Run\RunEvent;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Support\AiSseStream;
use App\Support\SpaceContext;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts, resumes, follows and cancels agent runs. Inline runs stream SSE;
 * a run that outlives the request continues on the queue and the client
 * follows it with `events?after=`.
 */
class AiRunController extends Controller
{
    public function store(StartAiRunRequest $request, Space $space, AiRunService $runs, SkillResolver $skills): Response
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

        $run = $runs->start(new StartRun(
            space: $space,
            actor: $request->user(),
            surface: $request->string('surface')->value(),
            skill: $skill,
            prompt: (string) $request->input('prompt', ''),
            context: $request->input('context', []),
            config: $request->filled('config_id') ? $space->aiConfigs()->find($request->input('config_id')) : null,
            idempotencyKey: $request->input('idempotency_key'),
            conversationId: $conversationId,
        ));

        // A repeated idempotency key returns the run it started.
        if (! $run->wasRecentlyCreated) {
            return response()->json(['data' => new AiRunResource($run)]);
        }

        if ($request->boolean('background')) {
            $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
            RunAgentJob::dispatch($run->id);

            return response()->json(['data' => new AiRunResource($run)], 202);
        }

        return $this->stream($runs, $run, $space);
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
