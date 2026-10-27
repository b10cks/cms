<?php

namespace App\Services\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiRunState;
use App\Events\Ai\AiRunEventsAvailable;
use App\Jobs\ReconcileAiRunCostJob;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiAttachment;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiConversation;
use App\Models\Management\AiMessage;
use App\Models\Management\AiRun;
use App\Models\Management\AiRunEvent;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\User;
use App\Services\Ai\Changesets\ChangesetRecorder;
use App\Services\Ai\Concerns\InteractsWithAiDriver;
use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Contracts\ProposesChanges;
use App\Services\Ai\Contracts\ProvidesSources;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Ai\Exceptions\ProviderCallFailed;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryTerm;
use App\Services\Ai\Run\ContextBuilder;
use App\Services\Ai\Run\OutputSchema;
use App\Services\Ai\Run\Questionnaire;
use App\Services\Ai\Run\RunBusy;
use App\Services\Ai\Run\RunEvent;
use App\Services\Ai\Run\RunEventType;
use App\Services\Ai\Run\RunOptions;
use App\Services\Ai\Run\RunToolFactory;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\TextDelta;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Skills\SkillManifest;
use App\Services\Ai\Support\JsonExtractor;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Ai\Tools\AskQuestionsTool;
use App\Services\Auth\AuthorizationService;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Owns the agent loop. A run advances in steps (one provider turn plus its
 * tool calls), checkpoints its transcript after every turn and after tool
 * results, and stops on completion, a questionnaire, a guard or a deadline.
 *
 * The same advance() runs inline (SSE) and in {@see RunAgentJob}; only the
 * event transport differs. All run state lives on the AiRun row, none in this
 * service, so it is safe as an Octane singleton.
 */
class AiRunService
{
    use InteractsWithAiDriver;

    public const OUTCOME_DONE = 'done';

    public const OUTCOME_PAUSED = 'paused';

    public const OUTCOME_YIELDED = 'yielded';

    private const BACKGROUND_TEXT_FLUSH = 1000;

    private const SELECTION_SURFACE = 'selection';

    private const TRANSLATE_SURFACE = 'translate';

    public function __construct(
        protected ModelRegistry $registry,
        private readonly ContextBuilder $context,
        private readonly RunToolFactory $tools,
        private readonly AuthorizationService $auth,
        private readonly GlossaryChecker $checker,
        private readonly AiThreads $threads,
    ) {}

    /**
     * Create a run, or return the existing one for the same idempotency key.
     *
     * @throws AiServiceException when the space cannot run AI
     */
    public function start(StartRun $start): AiRun
    {
        if ($start->idempotencyKey !== null && $existing = $this->findByKey($start)) {
            return $existing;
        }

        $config = $this->configFor($start);
        [$driver, $model] = $this->resolveSpaceDriver($start->space, $config);
        $manifest = $start->skill?->manifest;

        $sendsImages = collect($start->parts)->contains('type', 'image')
            || collect($start->attachments)->contains(fn (AiAttachment $file) => $file->kind === AiAttachment::KIND_IMAGE);
        if ($sendsImages && ! (app(ModelRegistry::class)->findModelForConfig($driver->getName(), $model)?->supportsVision ?? false)) {
            throw AiServiceException::modelNoVision();
        }

        $toolsAvailable = $driver->supportsToolCalls($model);
        $toolNames = $toolsAvailable ? RunToolFactory::namesFor($manifest?->tools) : [];
        $built = $this->context->build($start, $toolsAvailable && $toolNames !== []);

        $output = $this->outputFor($manifest, $start);
        $options = new RunOptions(
            $model,
            (int) ($config?->max_tokens ?? config('ai.runs.max_output_tokens')),
            (float) ($config?->temperature ?? 0.3),
            $output === SkillManifest::OUTPUT_JSON,
        );

        $thread = $start->opensThread && $start->conversationId === null ? $this->threads->open($start) : null;
        $conversationId = $thread->id ?? $start->conversationId;

        $attributes = [
            'space_id' => $start->space->id,
            'actor_id' => $start->actor->id,
            'conversation_id' => $conversationId,
            'idempotency_key' => $start->idempotencyKey,
            'surface' => $start->surface,
            'skill' => $manifest?->name,
            'ai_config_id' => $config?->id,
            'model' => "{$driver->getName()}:{$model}",
            'state' => AiRunState::Running,
            'mode' => AiRun::MODE_INLINE,
            'setup' => [
                ...$built['frozen'],
                'driver' => $driver->getName(),
                'tools' => $toolNames,
                'options' => $options->toArray(),
                'output' => $output,
                'schema' => $manifest?->schema,
                'budget' => $this->budget($manifest, $start->targets, $start->maxUsd),
                'corrections' => 0,
                'title' => Str::limit(trim((string) strtok($start->prompt, "\n")), 120, ''),
                'targets' => $start->targets,
            ],
            'input' => $built['input'],
            'transcript' => $built['transcript']->toArray(),
            'started_at' => now(),
        ];

        try {
            $run = AiRun::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent start with the same key won; its run has its thread.
            $thread?->delete();

            return $this->findByKey($start) ?? throw $e;
        }

        if ($conversationId !== null && (trim($start->prompt) !== '' || $start->attachments !== [])) {
            $message = AiMessage::create([
                'conversation_id' => $conversationId,
                'run_id' => $run->id,
                'role' => 'user',
                'content' => $start->prompt,
            ]);

            AiAttachment::query()
                ->whereIn('id', array_column($start->attachments, 'id'))
                ->whereNull('message_id')
                ->update(['message_id' => $message->id]);
        }

        // A new turn brings a settled thread back into the list.
        if ($conversationId !== null) {
            AiConversation::query()->whereKey($conversationId)->whereNotNull('archived_at')->update(['archived_at' => null]);
        }

        $this->threads->changed($run->space_id, $conversationId);

        return $run;
    }

    /**
     * Advance the run until it finishes, pauses for input or passes the
     * deadline. Inline callers pass the request deadline: the run then moves
     * to the background. Background slices pass theirs and get
     * {@see OUTCOME_YIELDED} back to re-dispatch.
     *
     * @return Generator<int, RunEvent, mixed, string>
     */
    public function advance(AiRun $run, ?float $deadline = null): Generator
    {
        $lock = Cache::lock("ai-run:{$run->id}", 300);

        if (! $lock->get()) {
            throw new RunBusy;
        }

        try {
            $run->refresh();

            if ($run->state !== AiRunState::Running) {
                return $run->state === AiRunState::AwaitingInput ? self::OUTCOME_PAUSED : self::OUTCOME_DONE;
            }

            $space = $run->space;
            $actor = $run->actor;
            $transcript = $run->transcriptObject();
            $tools = $this->tools->make($run, $space, $actor);

            if ($run->steps === 0 && $run->event_seq === 0) {
                yield $this->emit($run, RunEventType::RunStarted, ['skill' => $run->skill, 'surface' => $run->surface, 'conversation_id' => $run->conversation_id]);
            }

            while (true) {
                // A crash between a provider turn and its tool results resumes
                // here, without repeating the provider call.
                $pending = $transcript->pendingToolCalls();
                if ($pending !== []) {
                    $paused = yield from $this->runTools($run, $transcript, $tools, $pending);
                    if ($paused) {
                        return self::OUTCOME_PAUSED;
                    }

                    continue;
                }

                if (! $transcript->awaitsModel()) {
                    if (yield from $this->complete($run, $transcript, $transcript->lastAssistantText(), false, $space)) {
                        return self::OUTCOME_DONE;
                    }

                    continue;
                }

                if ($stop = $this->guard($run, $space, $actor)) {
                    yield from $this->stop($run, $transcript, $stop, $space);

                    return self::OUTCOME_DONE;
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    yield from $this->handOver($run, $transcript);

                    return self::OUTCOME_YIELDED;
                }

                try {
                    $turn = yield from $this->turn($run, $this->driverFor($run, $space), $transcript, $tools);
                } catch (AiServiceException $e) {
                    yield from $this->fail($run, $transcript, $e->reason, $e->getMessage());

                    return self::OUTCOME_DONE;
                } catch (ProviderCallFailed $e) {
                    yield from $this->fail($run, $transcript, 'provider_error', 'The AI provider returned an error.', $e);

                    return self::OUTCOME_DONE;
                }

                $run->steps++;
                $this->recordUsage($run, $turn);
                yield $this->emit($run, RunEventType::Usage, $this->usagePayload($run));
                $transcript->appendAssistant($turn->text, $turn->toolCalls);
                $this->checkpoint($run, $transcript);

                if ($turn->toolCalls === []) {
                    $finished = yield from $this->complete($run, $transcript, $turn->text, false, $space);
                    if ($finished) {
                        return self::OUTCOME_DONE;
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Validate answers to the pending questionnaire and resume. Only the run's
     * actor may answer, once: a concurrent second resume loses the state
     * transition and is rejected.
     *
     * @param  array<string, mixed>  $answers
     *
     * @throws RunBusy
     */
    public function answer(AiRun $run, User $user, array $answers): AiRun
    {
        abort_unless($run->actor_id === $user->id, 403);

        if ($run->state !== AiRunState::AwaitingInput || ! \is_array($run->questionnaire)) {
            throw new RunBusy('This AI run is not waiting for answers.');
        }

        $validated = Questionnaire::validateAnswers($run->questionnaire, $answers);

        $claimed = AiRun::query()
            ->whereKey($run->id)
            ->where('state', AiRunState::AwaitingInput->value)
            ->update(['state' => AiRunState::Running->value]);

        if ($claimed !== 1) {
            throw new RunBusy('This AI run was already answered.');
        }

        $run->refresh();
        $call = new ToolCall($run->questionnaire['tool_call_id'], AskQuestionsTool::NAME);
        $transcript = $run->transcriptObject()->appendToolResult($call, Questionnaire::toolResult($run->questionnaire, $validated));
        $run->questionnaire = null;
        $this->checkpoint($run, $transcript);

        return $run;
    }

    /**
     * Ask a run to stop. A loop that is advancing notices the flag at its next
     * guard; a run nobody is advancing (paused, between background slices, or
     * left by a closed tab) is cancelled right away.
     */
    public function cancel(AiRun $run): void
    {
        if ($run->isFinished()) {
            return;
        }

        $run->forceFill(['cancel_requested' => true])->save();
        $lock = Cache::lock("ai-run:{$run->id}", 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $run->refresh();
            if (! $run->isFinished()) {
                $this->finish($run, AiRunState::Cancelled);
                $this->emit($run, RunEventType::Error, ['reason' => 'cancelled', 'message' => 'The run was cancelled.']);
                $run->save();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Start and run to completion without streaming, for server-side features
     * (asset classification, data entry translation). Questionnaires are not
     * available here: the run's skill should not list `ask_questions`.
     */
    public function runToCompletion(StartRun $start): AiRun
    {
        $run = $this->start($start);

        foreach ($this->advance($run) as $event) {
            // drain
        }

        return $run->refresh();
    }

    /**
     * Stored events after a sequence number, for catch-up.
     *
     * @return Collection<int, AiRunEvent>
     */
    public function eventsAfter(AiRun $run, int $after, int $limit = 500): Collection
    {
        return $run->events()->where('seq', '>', $after)->orderBy('seq')->limit($limit)->get();
    }

    /**
     * @return Generator<int, RunEvent, mixed, TurnResult>
     */
    private function turn(AiRun $run, AiDriverInterface $driver, Transcript $transcript, ToolSet $tools): Generator
    {
        $turn = $driver->turn($transcript, $tools, RunOptions::fromArray($run->setup['options']));
        $buffer = '';

        foreach ($turn as $delta) {
            if (! $delta instanceof TextDelta || $delta->text === '') {
                continue;
            }

            if ($run->mode === AiRun::MODE_INLINE) {
                yield $this->emit($run, RunEventType::TextDelta, ['text' => $delta->text]);

                continue;
            }

            $buffer .= $delta->text;
            if (\strlen($buffer) >= self::BACKGROUND_TEXT_FLUSH) {
                yield $this->emit($run, RunEventType::TextDelta, ['text' => $buffer]);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            yield $this->emit($run, RunEventType::TextDelta, ['text' => $buffer]);
        }

        return $turn->getReturn();
    }

    /**
     * @param  list<ToolCall>  $calls
     * @return Generator<int, RunEvent, mixed, bool> true when the run paused for input
     */
    private function runTools(AiRun $run, Transcript $transcript, ToolSet $tools, array $calls): Generator
    {
        foreach ($calls as $call) {
            if ($call->name === AskQuestionsTool::NAME && $tools->has(AskQuestionsTool::NAME)) {
                try {
                    $questionnaire = Questionnaire::fromToolArguments($call->arguments);
                } catch (ToolError $e) {
                    $transcript->appendToolResult($call, json_encode(['error' => $e->getMessage()]) ?: '{}');

                    continue;
                }

                $run->questionnaire = ['tool_call_id' => $call->id, ...$questionnaire];
                $run->state = AiRunState::AwaitingInput;
                $this->checkpoint($run, $transcript);
                $this->threads->changed($run->space_id, $run->conversation_id, activity: false);
                yield $this->emit($run, RunEventType::Questionnaire, ['questionnaire' => $questionnaire]);
                $run->save();

                return true;
            }

            yield $this->emit($run, RunEventType::ToolStarted, ['id' => $call->id, 'name' => $call->name]);
            $result = $tools->execute($call);
            $transcript->appendToolResult($call, $result);
            $this->checkpoint($run, $transcript);

            $tool = $tools->get($call->name);
            if ($tool instanceof ProvidesSources) {
                foreach ($tool->takeSources() as $source) {
                    yield $this->emit($run, RunEventType::Source, ['source' => $source]);
                }
            }
            if ($tool instanceof ProposesChanges) {
                foreach ($tool->takeProposals() as $proposal) {
                    yield $this->emit($run, RunEventType::Proposal, ['proposal' => $proposal]);
                }
            }

            yield $this->emit($run, RunEventType::ToolFinished, [
                'id' => $call->id,
                'name' => $call->name,
                'ok' => ! str_starts_with($result, '{"error"'),
            ]);
        }

        return false;
    }

    /**
     * Finish the run with the final text. A JSON skill whose output misses its
     * schema gets a bounded number of corrections first.
     *
     * @return Generator<int, RunEvent, mixed, bool> false when a correction was requested
     */
    private function complete(AiRun $run, Transcript $transcript, string $text, bool $partial, Space $space): Generator
    {
        $data = null;
        $setup = $run->setup;

        if (($setup['output'] ?? null) === SkillManifest::OUTPUT_JSON) {
            $data = JsonExtractor::decode($text, true);
            $errors = \is_array($data)
                ? (\is_array($setup['schema'] ?? null) ? OutputSchema::validate($data, $setup['schema']) : [])
                : ['The answer is not valid JSON.'];

            if ($errors !== []) {
                if (! $partial && ($setup['corrections'] ?? 0) < config('ai.runs.max_output_corrections')) {
                    $setup['corrections'] = ($setup['corrections'] ?? 0) + 1;
                    $run->setup = $setup;
                    $transcript->appendUser("Your answer does not match the required output format:\n- ".implode("\n- ", $errors)."\nReply again with only the corrected output.");
                    $this->checkpoint($run, $transcript);

                    return false;
                }

                $data = \is_array($data) ? $data : null;
                $partial = true;
            }
        }

        // Fields the translation memory filled never went to the model.
        $remembered = $setup['translation_memory']['exact'] ?? [];
        if ($remembered !== [] && \is_array($data)) {
            $data = array_replace($remembered, $data);
        }

        $findings = $this->glossaryFindings($run, $text, $data);
        if ($findings !== []) {
            yield $this->emit($run, RunEventType::GlossaryFindings, ['findings' => $findings]);
        }

        if ($run->surface === self::SELECTION_SURFACE && trim($text) !== '') {
            yield $this->emit($run, RunEventType::Proposal, ['proposal' => $this->proposeSelection($run, $text)]);
        }

        if ($run->surface === self::TRANSLATE_SURFACE && \is_array($data)) {
            $this->recordTranslations($run, $data);
        }

        $run->result = ['text' => $text, 'data' => $data, 'glossary_findings' => $findings];
        $run->partial = $partial;
        $this->finish($run, AiRunState::Completed);
        $this->checkpoint($run, $transcript);

        if ($run->conversation_id !== null) {
            AiMessage::create([
                'conversation_id' => $run->conversation_id,
                'run_id' => $run->id,
                'role' => 'assistant',
                'content' => $text,
            ]);
        }

        yield $this->emit($run, RunEventType::Completed, [
            'result' => $run->result,
            'partial' => $partial,
            'usage' => $this->usagePayload($run),
        ]);
        $run->save();

        return true;
    }

    /**
     * @return array{reason: string, partial: bool}|null
     */
    private function guard(AiRun $run, Space $space, User $actor): ?array
    {
        if ((bool) AiRun::query()->whereKey($run->id)->value('cancel_requested')) {
            return ['reason' => 'cancelled', 'partial' => false];
        }

        if (! $this->auth->canInSpace($actor, $space, 'space.view')) {
            return ['reason' => 'access_revoked', 'partial' => false];
        }

        $budget = $run->setup['budget'];
        $elapsed = $run->started_at ? $run->started_at->diffInSeconds(now()) : 0;

        return match (true) {
            $run->steps >= (int) $budget['max_steps'] => ['reason' => 'max_steps', 'partial' => true],
            $run->input_tokens + $run->output_tokens >= (int) $budget['max_tokens'] => ['reason' => 'max_tokens', 'partial' => true],
            $run->cost_usd >= (float) $budget['max_usd'] => ['reason' => 'max_usd', 'partial' => true],
            $elapsed >= (int) $budget['max_seconds'] => ['reason' => 'max_seconds', 'partial' => true],
            default => null,
        };
    }

    /**
     * @param  array{reason: string, partial: bool}  $stop
     * @return Generator<int, RunEvent, mixed, void>
     */
    private function stop(AiRun $run, Transcript $transcript, array $stop, Space $space): Generator
    {
        if ($stop['partial']) {
            // A budget hit completes with what exists so far.
            $run->setup = [...$run->setup, 'stopped_by' => $stop['reason']];
            yield from $this->complete($run, $transcript, $transcript->lastAssistantText(), true, $space);

            return;
        }

        $state = $stop['reason'] === 'cancelled' ? AiRunState::Cancelled : AiRunState::Failed;
        $run->error = $stop['reason'];
        $this->finish($run, $state);
        $this->checkpoint($run, $transcript);
        yield $this->emit($run, RunEventType::Error, [
            'reason' => $stop['reason'],
            'message' => $state === AiRunState::Cancelled ? 'The run was cancelled.' : 'You no longer have access to this space.',
        ]);
        $run->save();
    }

    /**
     * Checkpoint and continue on the queue; the client follows over Reverb.
     *
     * @return Generator<int, RunEvent, mixed, void>
     */
    private function handOver(AiRun $run, Transcript $transcript): Generator
    {
        $wasInline = $run->mode === AiRun::MODE_INLINE;
        $run->mode = AiRun::MODE_BACKGROUND;
        $this->checkpoint($run, $transcript);

        if ($wasInline) {
            yield $this->emit($run, RunEventType::MovedToBackground, []);
            $run->save();
        }

        // Delayed so the inline request releases the run lock first.
        RunAgentJob::dispatch($run->id)->delay(now()->addSeconds(2));
    }

    /**
     * @return Generator<int, RunEvent, mixed, void>
     */
    public function fail(AiRun $run, ?Transcript $transcript, string $reason, string $message, ?\Throwable $e = null): Generator
    {
        $ref = (string) Str::uuid();

        if ($e !== null) {
            Log::error('AI run failed', ['run' => $run->id, 'ref' => $ref, 'reason' => $reason, 'exception' => $e]);
        }

        $run->error = $e !== null ? "{$reason}: {$ref}" : $reason;
        $this->finish($run, AiRunState::Failed);
        $this->checkpoint($run, $transcript ?? $run->transcriptObject());
        yield $this->emit($run, RunEventType::Error, ['reason' => $reason, 'message' => $e !== null ? "{$message} (ref: {$ref})" : $message]);
        $run->save();
    }

    private function finish(AiRun $run, AiRunState $state): void
    {
        $run->state = $state;
        $run->finished_at = now();
        $this->threads->changed($run->space_id, $run->conversation_id);

        if (($run->setup['driver'] ?? null) === 'openrouter' && ! empty($run->setup['generations'])) {
            ReconcileAiRunCostJob::dispatch($run->id)->delay(now()->addSeconds(30));
        }
    }

    /**
     * A skill's JSON output format fits one answer. A run over selected
     * entries or a Cmd+K task ends with a summary, its result is the changeset.
     */
    private function outputFor(?SkillManifest $manifest, StartRun $start): string
    {
        $output = $manifest?->output ?? SkillManifest::OUTPUT_TEXT;

        return $output === SkillManifest::OUTPUT_JSON && ($start->targets !== null || $start->surface === 'cmdk')
            ? SkillManifest::OUTPUT_TEXT
            : $output;
    }

    /**
     * Budgets from config and the skill. A confirmed bulk selection gets
     * enough steps to read and propose for each entry; the USD cap still holds.
     *
     * @param  array{type: string, ids: list<string>}|null  $targets
     * @return array<string, int|float>
     */
    private function budget(?SkillManifest $manifest, ?array $targets, ?float $maxUsd): array
    {
        $budget = array_merge(
            array_intersect_key(config('ai.runs'), array_flip(['max_steps', 'max_tokens', 'max_usd', 'max_seconds'])),
            $manifest?->budget ?? [],
        );

        if ($targets !== null) {
            $budget['max_steps'] = max((int) $budget['max_steps'], 2 * \count($targets['ids']) + 4);
        }

        if ($maxUsd !== null) {
            $budget['max_usd'] = $maxUsd;
        }

        return $budget;
    }

    /**
     * A rich text selection proposal is a single-entry changeset reviewed in
     * place. The open editor owns the working copy, so accepting applies there
     * and records the decision here.
     *
     * @return array<string, string|null>
     */
    private function proposeSelection(AiRun $run, string $text): array
    {
        $context = $run->input ?? [];
        $item = app(ChangesetRecorder::class)->record($run, [[
            'operation' => AiChangesetItem::OPERATION_UPDATE,
            'target_type' => AiChangesetItem::TARGET_SELECTION,
            'target_id' => \is_string($context['content_id'] ?? null) ? $context['content_id'] : null,
            'field_type' => 'text',
            'before' => (string) ($context['selection'] ?? ''),
            'after' => trim($text),
        ]], inline: true)[0];

        return [
            'changeset_id' => $item->changeset_id,
            'item_id' => $item->id,
            'target_type' => $item->target_type,
            'target_id' => $item->target_id,
            'path' => null,
        ];
    }

    /**
     * Editor translations apply in the open editor as they stream, so they are
     * recorded as already accepted items of a single-entry changeset. A field
     * edited before publish then feeds the learning loop. The editor sends the
     * entry and the field path of each unit it can address by block IDs.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function recordTranslations(AiRun $run, array $data): void
    {
        $context = $run->input ?? [];
        $contentId = $context['content_id'] ?? null;
        $paths = $context['paths'] ?? null;

        if (! \is_string($contentId) || ! \is_array($paths)) {
            return;
        }

        $items = [];
        foreach ($paths as $unit => $path) {
            $value = $data[$unit] ?? null;
            if (! \is_string($path) || $path === '' || ! \is_string($value) || trim($value) === '') {
                continue;
            }

            $items[] = [
                'operation' => AiChangesetItem::OPERATION_UPDATE,
                'target_type' => AiChangesetItem::TARGET_CONTENT,
                'target_id' => $contentId,
                'path' => $path,
                'field_type' => 'text',
                'before' => null,
                'after' => $value,
                'status' => AiChangesetItemStatus::Accepted,
                'decided_by_id' => $run->actor_id,
                'decided_at' => now(),
            ];
        }

        if ($items === []) {
            return;
        }

        // A newer translation of the same field supersedes what an earlier one would teach.
        AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $contentId)
            ->whereIn('path', array_column($items, 'path'))
            ->where('status', AiChangesetItemStatus::Accepted->value)
            ->whereNull('learned_at')
            ->update(['learned_at' => now()]);

        app(ChangesetRecorder::class)->record($run, $items, inline: true);
    }

    private function checkpoint(AiRun $run, Transcript $transcript): void
    {
        $run->transcript = $transcript->toArray();
        $run->save();
    }

    /**
     * Assign the next sequence number. Background events are stored for
     * catch-up and announced to the actor with IDs only.
     *
     * @param  array<string, mixed>  $payload
     */
    private function emit(AiRun $run, RunEventType $type, array $payload): RunEvent
    {
        $run->event_seq++;
        $event = new RunEvent($run->id, $run->event_seq, $type, $payload);

        if ($run->mode === AiRun::MODE_BACKGROUND) {
            AiRunEvent::create(['run_id' => $run->id, 'seq' => $event->seq, 'type' => $type->value, 'payload' => $payload]);
            AiRun::query()->whereKey($run->id)->update(['event_seq' => $run->event_seq]);
            broadcast(new AiRunEventsAvailable($run->actor_id, $run->id, $event->seq, $type->value));
        }

        return $event;
    }

    private function recordUsage(AiRun $run, TurnResult $turn): void
    {
        $usage = $turn->usage;
        $run->input_tokens += $usage->inputTokens;
        $run->output_tokens += $usage->outputTokens;
        $run->cached_tokens += $usage->cachedTokens;

        if ($usage->generationId !== null) {
            $run->setup = [...$run->setup, 'generations' => [...($run->setup['generations'] ?? []), $usage->generationId]];
        }

        $cost = $usage->costUsd;
        if ($cost === null && $run->model !== null) {
            $model = $this->registry->findModel($run->model);
            $cost = $model ? ($usage->inputTokens * $model->inputCost + $usage->outputTokens * $model->outputCost) / 1_000_000 : 0.0;
        }

        $run->cost_usd = round($run->cost_usd + $cost, 6);
    }

    /**
     * @return array{steps: int, input_tokens: int, output_tokens: int, cost_usd: float}
     */
    private function usagePayload(AiRun $run): array
    {
        return [
            'steps' => $run->steps,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'cost_usd' => $run->cost_usd,
        ];
    }

    /**
     * @param  array<mixed>|null  $data
     * @return list<array<string, string>>
     */
    private function glossaryFindings(AiRun $run, string $text, ?array $data): array
    {
        $glossary = $run->setup['glossary'] ?? null;

        if (! $glossary || ($glossary['terms'] ?? []) === []) {
            return [];
        }

        $output = $data !== null ? implode("\n", array_filter(Arr::flatten($data), 'is_string')) : $text;

        return $this->checker->check(
            (string) $glossary['source'],
            $output,
            (string) $glossary['source_locale'],
            (string) $glossary['target_locale'],
            array_map(fn (array $term) => GlossaryTerm::fromArray($term), $glossary['terms']),
        );
    }

    private function driverFor(AiRun $run, Space $space): AiDriverInterface
    {
        return $this->registry->getDriverForSpace($run->setup['driver'], $space)
            ?? throw AiServiceException::notProvisioned();
    }

    private function configFor(StartRun $start): ?SpaceAiConfig
    {
        $named = $start->skill?->manifest->config;

        if ($start->config === null && $named !== null) {
            return $start->space->aiConfigs()->where('name', $named)->first() ?? $start->space->defaultAiConfig;
        }

        return $start->config ?? $start->space->defaultAiConfig;
    }

    private function findByKey(StartRun $start): ?AiRun
    {
        return AiRun::query()
            ->where('space_id', $start->space->id)
            ->where('actor_id', $start->actor->id)
            ->where('idempotency_key', $start->idempotencyKey)
            ->first();
    }
}
