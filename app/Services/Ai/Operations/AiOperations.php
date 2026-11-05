<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationKind;
use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Enums\AiRunState;
use App\Enums\AiScope;
use App\Jobs\RunAgentJob;
use App\Models\Management\SpaceAiConfig;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\Asset;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Models\User;
use App\Policies\AiRunPolicy;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Assets\AssetPlan;
use App\Services\Ai\Assets\PlannedAssetStructure;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Changesets\PreflightEstimate;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\Run\ProviderRetry;
use App\Services\Ai\Run\RunRecovery;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Schedules\ScheduleRunner;
use App\Services\Ai\Skills\ResolvedSkill;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Auth\AuthorizationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Runs an approved plan over a frozen scope in bounded batches on the
 * existing agent loop. The ledger (`ai_operation_items`) accounts for every
 * entry; each batch is one background run over at most `batch_size` entries.
 *
 * Every state change goes through {@see advance()} or {@see runFinished()}
 * under one lock per operation, and both are safe to call again: a batch is
 * started with an idempotency key, settling only touches the entries of the
 * run it settles, and tools account for their entries in the commit of their write.
 * A worker that dies between any two steps leaves a state the recovery sweep
 * ({@see RunRecovery}) continues from by calling the same methods.
 * All state lives in the database; this service is safe as a singleton.
 *
 * Finishing is about entries, coverage about outputs: a completed operation may
 * still hold exceptions. `counts.coverage` ({@see OutputCoverage}) says which.
 */
class AiOperations
{
    public const PLAN_INSTRUCTIONS = 'You are drafting the plan for an AI content operation over the entries frozen in its scope. '
        .'The user message is the outcome the editor wants. The context holds the scope counts under `operation` and a sample of entries under `targets`; '
        .'read the sample (get_entry and the inspection tools you have). Then call propose_plan once with a concrete plan the editor can approve. '
        .'Change nothing: nothing is written before approval. After approval content goes straight to drafts, so say what will change. Finish with a short note on what the plan covers and what it leaves out.';

    public function __construct(
        private readonly AiRunService $runs,
        private readonly EntryScope $scope,
        private readonly AiThreads $threads,
        private readonly AuthorizationService $auth,
        private readonly ChangeTargets $targets,
        private readonly ModelRegistry $registry,
        private readonly SkillResolver $skills,
    ) {}

    /**
     * Freeze the scope into a draft operation. Unsupported entries are frozen
     * as skipped, so the counts the editor sees cover the whole scope.
     *
     * @throws ScopeTooLarge
     * @throws ValidationException when the scope is empty
     */
    public function create(CreateOperation $create): AiOperation
    {
        $type = $create->kind->targetType() ?? $create->scope['type'];
        $spec = [
            'type' => $type,
            'ids' => $create->scope['ids'] ?? null,
            'root_id' => $create->scope['root_id'] ?? null,
            'recursive' => (bool) ($create->scope['recursive'] ?? true),
            'filters' => array_intersect_key((array) ($create->scope['filters'] ?? []), array_flip(EntryScope::RECURSIVE_FILTERS)),
        ];
        $limit = (int) config('ai.operations.max_items');
        $canonical = $create->kind->canonicalOnly();

        $total = $this->scope->count($type, $spec, $canonical);
        if ($total > $limit) {
            throw new ScopeTooLarge($total, $limit);
        }
        if ($total === 0) {
            throw ValidationException::withMessages(['scope' => ['The scope has no entries.']]);
        }

        $surface = ScheduleRunner::SURFACES[$type];
        $conversationId = $create->conversationId ?? $this->threads->open(new StartRun(
            space: $create->space,
            actor: $create->actor,
            surface: $surface,
            skill: $create->skill,
            prompt: $create->prompt,
        ))->id;

        $operation = AiOperation::query()->getConnection()->transaction(function () use ($create, $spec, $surface, $conversationId) {
            $operation = AiOperation::create([
                'space_id' => $create->space->id,
                'actor_id' => $create->actor->id,
                'conversation_id' => $conversationId,
                'schedule_id' => $create->scheduleId,
                'kind' => $create->kind,
                'skill' => $create->skill?->manifest->name,
                'skill_ref' => $create->skill?->freeze(),
                'surface' => $surface,
                'ai_config_id' => $create->config?->id,
                'prompt' => $create->prompt,
                'scope' => $spec,
                'counts' => [],
                'state' => AiOperationState::Draft,
            ]);
            $this->freeze($operation);

            return $operation;
        });

        $this->threads->changed($operation->space_id, $operation->conversation_id);

        return $operation;
    }

    /**
     * Walk the scope into the ledger, one row per entry, and store the counts
     * the editor previews with `scope_hash`, the hash of the frozen entry IDs.
     *
     * @throws ScopeTooLarge
     */
    private function freeze(AiOperation $operation): void
    {
        $type = $operation->scope['type'];
        $kind = $operation->kind;
        $limit = (int) config('ai.operations.max_items');
        $counts = ['total' => 0, 'supported' => 0, 'unsupported' => 0, 'groups' => [], 'tokens' => 0];
        $ids = [];

        $this->scope->each($type, $operation->scope, function (Collection $chunk) use ($operation, $kind, $type, $limit, &$counts, &$ids) {
            $now = now();
            $rows = [];
            foreach ($chunk as $model) {
                $entry = $this->scope->describe($model) + ['unsupported' => $kind->unsupportedReason($model)];
                $counts['total']++;
                $counts[$entry['unsupported'] ? 'unsupported' : 'supported']++;
                $counts['groups'][$entry['group'] ?? 'other'] = ($counts['groups'][$entry['group'] ?? 'other'] ?? 0) + 1;
                $counts['tokens'] += $entry['unsupported'] ? 0 : $entry['tokens'];
                $ids[] = $entry['id'];
                $rows[] = [
                    'operation_id' => $operation->id,
                    'position' => $counts['total'],
                    'target_type' => $type,
                    'target_id' => $entry['id'],
                    'label' => mb_substr($entry['name'], 0, 255),
                    'group' => $entry['group'] !== null ? mb_substr($entry['group'], 0, 120) : null,
                    'base_version_id' => $entry['version_id'],
                    'status' => $entry['unsupported'] ? AiOperationItemStatus::Skipped->value : AiOperationItemStatus::Pending->value,
                    'reason' => $entry['unsupported'],
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Entries added while the scope was walked could push it past the limit.
            if ($counts['total'] > $limit) {
                throw new ScopeTooLarge($counts['total'], $limit);
            }

            AiOperationItem::query()->insert($rows);
        }, $kind->canonicalOnly());

        if ($counts['total'] === 0) {
            throw ValidationException::withMessages(['scope' => ['The scope has no entries.']]);
        }

        $config = $operation->ai_config_id !== null ? $this->config($operation) : $operation->space->defaultAiConfig;
        $counts['estimate'] = PreflightEstimate::forTotals(
            $counts['supported'],
            $counts['tokens'],
            $this->registry->findModelForConfig($config?->driver, $config?->model),
            PreflightEstimate::observedCostPerEntry($operation->space_id, $operation->skill),
        );
        $counts['scope_hash'] = self::scopeHash($ids);
        $operation->forceFill(['counts' => $counts])->save();
    }

    /**
     * Freeze the draft's scope again, as it is now, after approval refused a
     * scope that changed since the preview. The editor reviews the new counts
     * and approves with the new `scope_hash`.
     *
     * @throws OperationConflict|ScopeTooLarge|ValidationException
     */
    public function refreshScope(AiOperation $operation): AiOperation
    {
        $this->locked($operation, function (AiOperation $operation) {
            if ($operation->state !== AiOperationState::Draft) {
                throw new OperationConflict('The scope froze when the plan was approved.');
            }

            $operation->getConnection()->transaction(function () use ($operation) {
                $operation->items()->delete();
                $this->freeze($operation);
            });
        });
        $this->threads->changed($operation->space_id, $operation->conversation_id);

        return $operation->refresh();
    }

    /**
     * Refuse approval when the scope now holds other entries than the
     * preview the editor saw, or the editor saw other counts than the ledger.
     *
     * @throws OperationConflict
     */
    private function ensureScopeUnchanged(AiOperation $operation, ?string $scopeHash): void
    {
        $frozen = array_fill_keys(AiOperationItem::query()->where('operation_id', $operation->id)->pluck('target_id')->all(), true);
        $added = 0;

        $this->scope->each($operation->scope['type'], $operation->scope, function (Collection $chunk) use (&$frozen, &$added) {
            foreach ($chunk as $entry) {
                isset($frozen[$entry->getKey()]) ? $frozen[$entry->getKey()] = false : $added++;
            }
        }, $operation->kind->canonicalOnly());
        $removed = \count(array_filter($frozen));

        if ($added > 0 || $removed > 0 || ($scopeHash !== null && ! hash_equals((string) ($operation->counts['scope_hash'] ?? ''), $scopeHash))) {
            throw new OperationConflict(
                'The scope changed since its preview. Refresh it, review the new counts and approve again.',
                'scope_changed',
                ['added' => $added, 'removed' => $removed],
            );
        }
    }

    /** @param  list<string>  $ids */
    private static function scopeHash(array $ids): string
    {
        sort($ids, SORT_STRING);

        return hash('sha256', implode("\n", $ids));
    }

    /**
     * Start a background planning run in the operation's thread over a
     * sample of the scope. Its only write is the draft plan.
     *
     * @throws OperationConflict|AiServiceException
     */
    public function startPlanning(AiOperation $operation): AiRun
    {
        if ($operation->state !== AiOperationState::Draft) {
            throw new OperationConflict('The plan is already approved.');
        }

        $sample = $this->sample($operation, (int) config('ai.operations.plan_sample'));
        $run = $this->runs->start(new StartRun(
            space: $operation->space,
            actor: $operation->actor,
            surface: $operation->surface,
            skill: $this->skill($operation),
            prompt: $operation->prompt,
            context: [
                'targets' => ['type' => $operation->scope['type'], 'entries' => $sample],
                'operation' => $this->runContext($operation, null),
            ],
            config: $this->config($operation),
            conversationId: $operation->conversation_id,
            instructions: self::PLAN_INSTRUCTIONS,
            targets: ['type' => $operation->scope['type'], 'ids' => array_column($sample, 'id')],
            operation: ['id' => $operation->id, 'phase' => OperationContext::PHASE_PLAN],
        ));

        $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
        $operation->forceFill(['plan_run_id' => $run->id])->save();
        RunAgentJob::dispatch($run->space_id, $run->id);

        return $run;
    }

    /**
     * Replace the draft plan, as the editor edited it.
     *
     * @param  array<string, mixed>  $plan
     *
     * @throws OperationConflict|ValidationException
     */
    public function savePlan(AiOperation $operation, array $plan): AiOperation
    {
        $this->locked($operation, function (AiOperation $operation) use ($plan) {
            if ($operation->state !== AiOperationState::Draft) {
                throw new OperationConflict('The plan is already approved and cannot change.');
            }

            $operation->forceFill(['plan' => $this->validPlan($operation, $plan)])->save();
        });

        return $operation->refresh();
    }

    /**
     * Freeze the plan the editor saw and start the first batch. The plan hash
     * proves which plan was approved; a plan changed since is refused. The
     * scope freezes here too: entries that joined or left it since the
     * preview, or a `scope_hash` other than the ledger's, refuse approval
     * with `scope_changed` until the editor refreshes and approves again.
     *
     * @throws OperationConflict|ValidationException
     */
    public function approve(AiOperation $operation, User $user, string $planHash, float $maxUsd, ?string $scopeHash = null): AiOperation
    {
        $this->locked($operation, function (AiOperation $operation) use ($user, $planHash, $maxUsd, $scopeHash) {
            if ($operation->state !== AiOperationState::Draft) {
                throw new OperationConflict('This operation was already approved.');
            }
            if ($operation->plan === null) {
                throw ValidationException::withMessages(['plan' => ['Draft a plan before approving.']]);
            }
            // The scope freezes at approval: the ledger must hold what the scope holds now.
            $this->ensureScopeUnchanged($operation, $scopeHash);

            $plan = $this->validPlan($operation, $operation->plan);
            if (! hash_equals(OperationPlan::hash($plan), $planHash)) {
                throw new OperationConflict('The plan changed since you reviewed it. Review it again.', 'plan_changed');
            }

            $operation->getConnection()->transaction(function () use ($operation, $plan, $planHash, $user, $maxUsd) {
                app(KnowledgeResolver::class)->pinOperationPolicy($operation);
                $operation->forceFill([
                    'approved_plan' => $plan,
                    'approved_plan_hash' => $planHash,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'max_usd' => $maxUsd,
                    'state' => AiOperationState::Running,
                ])->save();
                $this->freezeOutputRequirements($operation);
            });
        });

        $this->advance($operation);

        return $operation->refresh();
    }

    private function freezeOutputRequirements(AiOperation $operation): void
    {
        if (! isset($operation->approved_plan['outputs']) && ! isset($operation->approved_plan['metadata'])) {
            return;
        }

        $triage = config("ai.operations.triage.{$operation->kind->value}");
        if (! \is_string($triage)) {
            return;
        }

        AiOperationItem::query()->where('operation_id', $operation->id)
            ->where('status', AiOperationItemStatus::Pending->value)
            ->chunkById(500, function (Collection $items) use ($operation, $triage) {
                $requirements = app($triage)->triage($operation, $items);
                foreach ($items as $item) {
                    if (isset($requirements[$item->id]['expected'])) {
                        $item->forceFill(['detail' => [...($item->detail ?? []), 'expected_parts' => $requirements[$item->id]['expected']]])->save();
                    }
                }
            });
    }

    /**
     * Start the next batch, or settle a finished one first. Safe to call any
     * time; with `$recover` it also re-dispatches a batch run left without a
     * worker (the resume path).
     */
    public function advance(AiOperation $operation, bool $recover = false): void
    {
        try {
            $dispatch = $this->locked($operation, fn (AiOperation $operation) => $this->step($operation, $recover));
        } catch (OperationConflict) {
            // Another worker is advancing it right now.
            return;
        }

        if ($dispatch instanceof AiRun) {
            // Outside the lock: a sync queue runs the job right here.
            RunAgentJob::dispatch($dispatch->space_id, $dispatch->id);
        }
    }

    /** One advance step under the lock; returns a batch run to dispatch. */
    private function step(AiOperation $operation, bool $recover): ?AiRun
    {
        if ($operation->state !== AiOperationState::Running) {
            return null;
        }

        if ($operation->current_run_id !== null) {
            $run = AiRun::query()->find($operation->current_run_id);
            if ($run !== null && ! $run->isFinished()) {
                return $recover && $run->state === AiRunState::Running && ! $run->isLeased() ? $run : null;
            }

            $this->settle($operation, $run);
        } elseif ($this->claimedWithoutRun($operation)) {
            // A crash between claiming a batch and starting its run; the idempotency key finds a run already made.
            return $this->launch($operation, $operation->batch_count);
        }

        return $operation->state === AiOperationState::Running ? $this->startBatch($operation) : null;
    }

    /** A batch or planning run finished; settle it if it is the operation's current batch. */
    public function runFinished(AiRun $run): void
    {
        $operation = $run->operation_id !== null ? AiOperation::query()->find($run->operation_id) : null;
        if ($operation === null) {
            return;
        }

        if ($operation->plan_run_id === $run->id) {
            $this->threads->changed($operation->space_id, $operation->conversation_id);

            return;
        }

        try {
            $settled = $this->locked($operation, function (AiOperation $operation) use ($run) {
                if ($operation->current_run_id !== $run->id) {
                    return false;
                }

                $this->settle($operation, $run);

                return true;
            });
        } catch (OperationConflict) {
            // Whoever holds the lock may be gone next; the recovery sweep settles the run then.
            return;
        }

        if ($settled) {
            $this->advance($operation);
        }
    }

    /**
     * Whether a run that lost its worker should go on: only as the operation's
     * current batch, or as the planning run of its draft, and not once the
     * editor asked to pause after that batch.
     */
    public function mayContinue(AiRun $run): bool
    {
        $operation = $run->operation_id !== null ? AiOperation::query()->find($run->operation_id) : null;

        return match (true) {
            $run->operation_id === null => true,
            $operation === null => false,
            $operation->plan_run_id === $run->id => $operation->state === AiOperationState::Draft,
            default => $operation->state === AiOperationState::Running && $operation->current_run_id === $run->id && ! $operation->pause_requested,
        };
    }

    /**
     * Bring the operation's spend in line with what its batch run has cost so
     * far. `counted_cost_usd` holds the run's share of `spent_usd`, so only the
     * difference moves, up or down, once. Settling and cost reconciliation both
     * come here, in any order, any number of times. A lower total frees budget
     * only for an explicit resume: nothing here changes the operation's state.
     */
    public function syncSpend(AiRun $run): void
    {
        if ($run->operation_id === null) {
            return;
        }

        AiOperation::query()->getConnection()->transaction(function () use ($run) {
            $current = AiRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail(['id', 'operation_id', 'cost_usd', 'counted_cost_usd']);
            $difference = round($current->cost_usd - $current->counted_cost_usd, 6);
            $operation = AiOperation::query()->whereKey($current->operation_id);

            // Planning happens before approval and is not part of the approved budget.
            if ($difference == 0 || $operation->value('plan_run_id') === $current->id) {
                return;
            }

            $operation->increment('spent_usd', $difference);
            AiRun::query()->whereKey($current->id)->update(['counted_cost_usd' => $current->cost_usd]);
        });
    }

    /**
     * Pause after the current batch, or now when none runs.
     *
     * @throws OperationConflict
     */
    public function pause(AiOperation $operation): AiOperation
    {
        $this->locked($operation, function (AiOperation $operation) {
            if ($operation->state !== AiOperationState::Running) {
                throw new OperationConflict('Only a running operation can pause.');
            }

            $operation->current_run_id !== null
                ? $operation->forceFill(['pause_requested' => true])->save()
                : $this->pauseFor($operation, AiOperationPauseReason::User);
        });

        return $operation->refresh();
    }

    /**
     * Continue a paused operation, or recover a running one whose batch lost
     * its worker. A spent budget needs {@see extendBudget()} instead.
     *
     * @throws OperationConflict
     */
    public function resume(AiOperation $operation): AiOperation
    {
        $this->locked($operation, function (AiOperation $operation) {
            if ($operation->state === AiOperationState::Running) {
                return;
            }
            if ($operation->state !== AiOperationState::Paused) {
                throw new OperationConflict('Only a paused operation can resume.');
            }
            if ($operation->remainingUsd() <= 0) {
                throw new OperationConflict('The budget is spent. Extend it to continue.', 'budget');
            }

            $operation->forceFill(['state' => AiOperationState::Running, 'pause_reason' => null, 'pause_requested' => false, 'error' => null])->save();
        });

        $this->advance($operation, recover: true);

        return $operation->refresh();
    }

    /**
     * Raise the shared budget. Never automatic: only this call lets a
     * budget-paused operation spend more, and it resumes it.
     *
     * @throws OperationConflict|ValidationException
     */
    public function extendBudget(AiOperation $operation, float $maxUsd): AiOperation
    {
        $resume = $this->locked($operation, function (AiOperation $operation) use ($maxUsd) {
            if (! \in_array($operation->state, [AiOperationState::Running, AiOperationState::Paused], true)) {
                throw new OperationConflict('Only an approved, unfinished operation has a budget to extend.');
            }
            if ($maxUsd <= $operation->spent_usd) {
                throw ValidationException::withMessages(['max_usd' => ['The new budget must be above what was already spent ($'.number_format($operation->spent_usd, 2).').']]);
            }

            $operation->forceFill(['max_usd' => $maxUsd])->save();

            return $operation->state === AiOperationState::Paused && $operation->pause_reason === AiOperationPauseReason::Budget;
        });

        return $resume ? $this->resume($operation) : $operation->refresh();
    }

    /**
     * Stop for good. Entries still owed stay pending and count as not
     * processed; what was written stays in its drafts, and proposals made so far
     * stay open for a person.
     *
     * @throws OperationConflict
     */
    public function cancel(AiOperation $operation): AiOperation
    {
        $run = $this->locked($operation, function (AiOperation $operation) {
            if ($operation->state->isFinished()) {
                throw new OperationConflict('This operation already finished.');
            }

            $operation->forceFill(['state' => AiOperationState::Cancelled, 'finished_at' => now(), 'pause_requested' => false, 'counts' => [...$operation->counts, 'coverage' => OutputCoverage::of($operation)]])->save();
            $this->threads->changed($operation->space_id, $operation->conversation_id);

            return $operation->current_run_id !== null ? AiRun::query()->find($operation->current_run_id) : null;
        });

        if ($run instanceof AiRun) {
            $this->runs->cancel($run);
            // A run nobody was advancing is cancelled right away; settle it here.
            if ($run->refresh()->isFinished()) {
                $this->runFinished($run);
            }
        }

        return $operation->refresh();
    }

    /**
     * Entries matching the scope now that were not frozen into it.
     *
     * @return array{count: int, entries: list<array{id: string, name: string}>}
     */
    public function arrivals(AiOperation $operation, int $sample = 20): array
    {
        $frozen = array_fill_keys(AiOperationItem::query()->where('operation_id', $operation->id)->pluck('target_id')->all(), true);
        $count = 0;
        $entries = [];

        $this->scope->each($operation->scope['type'], $operation->scope, function (Collection $chunk) use ($frozen, $sample, &$count, &$entries) {
            foreach ($chunk as $entry) {
                if (! isset($frozen[$entry->getKey()])) {
                    $count++;
                    if (\count($entries) < $sample) {
                        $described = $this->scope->describe($entry);
                        $entries[] = ['id' => $described['id'], 'name' => $described['name']];
                    }
                }
            }
        }, $operation->kind->canonicalOnly());

        return ['count' => $count, 'entries' => $entries];
    }

    /**
     * Move a batch's entries on: written, proposed and reported ones are done, the
     * rest return to the queue. A budget stop, a cancellation, lost access or a
     * provider outage is not the entry's fault and costs no attempt.
     */
    private function settle(AiOperation $operation, ?AiRun $run): void
    {
        $operation->getConnection()->transaction(fn () => $this->settleBatch($operation, $run));
        $this->threads->changed($operation->space_id, $operation->conversation_id);
    }

    private function settleBatch(AiOperation $operation, ?AiRun $run): void
    {
        $batch = AiOperationItem::query()
            ->where('operation_id', $operation->id)
            ->where('batch', $operation->batch_count)
            ->where('status', AiOperationItemStatus::Running->value);

        $stoppedBy = $run?->setup['stopped_by'] ?? null;
        $noFault = $run !== null && ($stoppedBy === 'max_usd' || $run->state === AiRunState::Cancelled || $run->error === 'access_revoked' || ProviderRetry::sparesEntries($run));
        $failed = $run === null || $run->state === AiRunState::Failed;

        if (! $noFault) {
            $reason = $failed ? 'batch_failed' : 'not_processed';
            $spent = (clone $batch)->where('attempts', '>=', (int) config('ai.operations.max_attempts') - 1);
            (clone $spent)->get()->each(fn (AiOperationItem $item) => $item->closeOpenParts($operation->expectedParts(), $reason));
            (clone $batch)->increment('attempts');
            (clone $batch)->where('attempts', '>=', (int) config('ai.operations.max_attempts'))->update([
                'status' => AiOperationItemStatus::Failed->value,
                'reason' => $reason,
            ]);
        }
        (clone $batch)->update(['status' => AiOperationItemStatus::Pending->value, 'run_id' => null]);

        if ($run !== null) {
            $this->syncSpend($run);
            $operation->spent_usd = (float) AiOperation::query()->whereKey($operation->id)->value('spent_usd');
            $operation->syncOriginalAttribute('spent_usd');
            $operation->input_tokens += $run->input_tokens;
            $operation->output_tokens += $run->output_tokens;
            $this->countRetries($operation, $run);
        }
        $operation->current_run_id = null;

        if ($operation->state === AiOperationState::Running) {
            $reason = match (true) {
                $run?->error === 'access_revoked' => AiOperationPauseReason::AccessRevoked,
                $run?->state === AiRunState::Cancelled && ! $operation->pause_requested => AiOperationPauseReason::BatchCancelled,
                $failed => AiOperationPauseReason::BatchFailed,
                $stoppedBy === 'max_usd' || $operation->remainingUsd() <= 0 => AiOperationPauseReason::Budget,
                $operation->pause_requested => AiOperationPauseReason::User,
                default => null,
            };

            if ($reason !== null && $this->owed($operation) > 0) {
                $operation->error = $failed ? $run?->error : null;
                $this->pauseFor($operation, $reason);
            }
        }

        $operation->save();
    }

    /**
     * Keep the run's retries on the operation for its receipt. Unconfirmed
     * calls may have been billed beyond what `spent_usd` shows.
     */
    private function countRetries(AiOperation $operation, AiRun $run): void
    {
        $retry = ProviderRetry::state($run);
        $counts = $operation->counts;

        if ($retry['total'] > 0) {
            $counts['provider_retries'] = ($counts['provider_retries'] ?? 0) + $retry['total'];
        }
        if ($retry['unconfirmed_calls'] > 0) {
            $counts['unconfirmed_provider_calls'] = ($counts['unconfirmed_provider_calls'] ?? 0) + $retry['unconfirmed_calls'];
        }

        $operation->counts = $counts;
    }

    /**
     * Claim the next entries and start their batch run. Before any model
     * call it rechecks the actor's access, each entry's existence and
     * version, and the kind's deterministic triage.
     */
    private function startBatch(AiOperation $operation): ?AiRun
    {
        if (! $this->mayRun($operation)) {
            $this->pauseFor($operation, AiOperationPauseReason::AccessRevoked);
            $operation->save();

            return null;
        }

        $size = max(1, (int) config('ai.operations.batch_size'));
        $claimed = [];

        while (\count($claimed) < $size) {
            $candidates = AiOperationItem::query()
                ->where('operation_id', $operation->id)
                ->where('status', AiOperationItemStatus::Pending->value)
                ->whereNotIn('id', $claimed)
                ->orderBy('position')
                ->limit($size)
                ->get();

            if ($candidates->isEmpty()) {
                break;
            }

            $claimed = [...$claimed, ...$this->recheck($operation, $candidates)];
        }

        if ($claimed === []) {
            $this->finishIfDone($operation);

            return null;
        }

        if ($operation->remainingUsd() <= 0) {
            $this->pauseFor($operation, AiOperationPauseReason::Budget);
            $operation->save();

            return null;
        }

        $number = $operation->batch_count + 1;
        // Together: claimed entries under an older batch number would stay `running` for good.
        $operation->getConnection()->transaction(function () use ($operation, $claimed, $size, $number) {
            AiOperationItem::query()->whereIn('id', \array_slice($claimed, 0, $size))->update([
                'status' => AiOperationItemStatus::Running->value,
                'batch' => $number,
                'run_id' => null,
            ]);
            $operation->forceFill(['batch_count' => $number])->save();
        });

        return $this->launch($operation, $number);
    }

    /**
     * Start (or, after a crash, find) the run of a claimed batch.
     */
    private function launch(AiOperation $operation, int $number): ?AiRun
    {
        $items = AiOperationItem::query()
            ->where('operation_id', $operation->id)
            ->where('batch', $number)
            ->where('status', AiOperationItemStatus::Running->value)
            ->orderBy('position')
            ->get(['id', 'target_type', 'target_id', 'label', 'detail']);
        $type = $operation->scope['type'];
        $assets = $operation->kind === AiOperationKind::Assets
            ? AssetPlan::forOperation((array) $operation->approved_plan, app(PlannedAssetStructure::class)->materialize($operation))->toArray()
            : null;

        try {
            $run = $this->runs->start(new StartRun(
                space: $operation->space,
                actor: $operation->actor,
                surface: $operation->surface,
                skill: $this->skill($operation),
                prompt: $operation->prompt,
                context: [
                    'targets' => ['type' => $type, 'entries' => $items->map(fn (AiOperationItem $item) => array_filter([
                        'id' => $item->target_id,
                        'name' => $item->label,
                        'changed_since_frozen' => ($item->detail['version_changed'] ?? false) ?: null,
                        'done' => array_map(fn (array $part) => $part['outcome'], array_diff_key((array) ($item->detail['parts'] ?? []), array_flip($item->openParts($operation->expectedParts())))) ?: null,
                        'owed' => $item->openParts($operation->expectedParts()) ?: null,
                        'may_keep' => array_values(array_intersect((array) ($item->detail['overwritable'] ?? []), $item->openParts($operation->expectedParts()))) ?: null,
                        'answers' => $item->answersForBatch($operation->expectedParts()) ?: null,
                    ]))->all()],
                    'operation' => $this->runContext($operation, $number),
                ],
                config: $this->config($operation),
                idempotencyKey: "operation:{$operation->id}:{$number}",
                instructions: $this->batchInstructions($number).($operation->kind === AiOperationKind::Translation && isset($operation->approved_plan['metadata']) ? self::LINKED_METADATA : ''),
                targets: ['type' => $type, 'ids' => $items->pluck('target_id')->all()],
                maxUsd: $operation->remainingUsd(),
                operation: ['id' => $operation->id, 'phase' => OperationContext::PHASE_BATCH, 'batch' => $number, 'plan_hash' => (string) $operation->approved_plan_hash],
                translation: $operation->kind === AiOperationKind::Translation
                    ? array_intersect_key($operation->approved_plan, array_flip(['locales', 'include', 'metadata']))
                    : null,
                assets: $assets,
            ));
        } catch (AiServiceException $e) {
            AiOperationItem::query()->where('operation_id', $operation->id)->where('batch', $number)
                ->where('status', AiOperationItemStatus::Running->value)
                ->update(['status' => AiOperationItemStatus::Pending->value]);
            $operation->error = $e->getMessage();
            $this->pauseFor($operation, AiOperationPauseReason::BatchFailed);
            $operation->save();

            return null;
        }

        if ($run->mode !== AiRun::MODE_BACKGROUND) {
            $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
        }
        AiOperationItem::query()->whereIn('id', $items->pluck('id'))->update(['run_id' => $run->id]);
        $operation->forceFill(['current_run_id' => $run->id])->save();

        return $run;
    }

    /**
     * Drop entries that are gone, flag changed versions and apply the kind's
     * triage. Returns the IDs of entries that still need the model.
     *
     * @param  Collection<int, AiOperationItem>  $items
     * @return list<int>
     */
    private function recheck(AiOperation $operation, Collection $items): array
    {
        $type = $operation->scope['type'];
        $versions = $this->currentVersions($type, $items->pluck('target_id')->all());
        $live = collect();

        foreach ($items as $item) {
            if (! \array_key_exists($item->target_id, $versions)) {
                $item->forceFill(['status' => AiOperationItemStatus::Skipped, 'reason' => 'deleted'])->save();

                continue;
            }

            if ($item->base_version_id !== null && $versions[$item->target_id] !== $item->base_version_id && ! ($item->detail['version_changed'] ?? false)) {
                $item->forceFill(['detail' => [...($item->detail ?? []), 'version_changed' => true]])->save();
            }

            $live->push($item);
        }

        $triage = config("ai.operations.triage.{$operation->kind->value}");
        $decided = \is_string($triage) && $live->isNotEmpty() ? app($triage)->triage($operation, $live) : [];
        $expected = $operation->expectedParts();

        foreach ($decided as $id => $outcome) {
            $item = $live->firstWhere('id', $id);
            if ($item === null) {
                continue;
            }

            if (($outcome['reason'] ?? null) === 'pending_review' && $this->retainAnsweredQuestions($item, null, $expected)) {
                continue;
            }

            if (\in_array($outcome['status'] ?? null, [AiOperationItemStatus::Unchanged, AiOperationItemStatus::Skipped], true)) {
                $item->forceFill([
                    'status' => $outcome['status'],
                    'reason' => mb_substr($outcome['reason'], 0, 60),
                    'detail' => [...($item->detail ?? []), ...($outcome['detail'] ?? [])],
                ])->save();

                continue;
            }

            if (isset($outcome['expected'])) {
                $item->forceFill(['detail' => [
                    ...($item->detail ?? []),
                    'expected_parts' => $item->detail['expected_parts'] ?? $outcome['expected'],
                    'overwritable' => $outcome['overwritable'] ?? [],
                ]])->save();
            }

            // Parts code settled; the entry still goes to the model for the rest.
            foreach ($outcome['parts'] ?? [] as $part => ['outcome' => $result, 'reason' => $reason]) {
                if ($reason === 'pending_review' && $this->retainAnsweredQuestions($item, (string) $part, $expected)) {
                    continue;
                }
                $item->account($result, $reason, (string) $part, $expected);
            }
        }

        return $live->filter(fn (AiOperationItem $item) => $item->status === AiOperationItemStatus::Pending)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Current version per existing entry; entries without versions map to null.
     *
     * @param  list<string>  $ids
     * @return array<string, string|null>
     */
    private function currentVersions(string $type, array $ids): array
    {
        return match ($type) {
            AiChangesetItem::TARGET_CONTENT => Content::query()->whereIn('id', $ids)->pluck('current_version_id', 'id')->all(),
            AiChangesetItem::TARGET_ASSET => array_fill_keys(Asset::query()->whereIn('id', $ids)->pluck('id')->all(), null),
            AiChangesetItem::TARGET_DATA_ENTRY => array_fill_keys(DataEntry::query()->whereIn('id', $ids)->pluck('id')->all(), null),
            default => [],
        };
    }

    private function finishIfDone(AiOperation $operation): void
    {
        if ($this->owed($operation) === 0) {
            $operation->forceFill([
                'state' => AiOperationState::Completed,
                'finished_at' => now(),
                'pause_requested' => false,
                'counts' => [...$operation->counts, 'coverage' => OutputCoverage::of($operation)],
            ])->save();
            $this->threads->changed($operation->space_id, $operation->conversation_id);
        }
    }

    /** Keep an answered output visible when another proposal prevents its retry. */
    private function retainAnsweredQuestions(AiOperationItem $item, ?string $part, ?array $expected): bool
    {
        $retained = false;
        foreach ($item->answersForBatch($expected) as $answer) {
            if ($part !== null && $answer['part'] !== $part) {
                continue;
            }
            $item->account(ReportsOutcomes::BLOCKED, 'pending_review', $answer['part'], $expected,
                'Another proposal needs review before this output can resume. The recorded answer is retained.',
                $answer['question']);
            $retained = true;
        }

        return $retained;
    }

    private function claimedWithoutRun(AiOperation $operation): bool
    {
        return $operation->batch_count > 0 && AiOperationItem::query()
            ->where('operation_id', $operation->id)
            ->where('batch', $operation->batch_count)
            ->where('status', AiOperationItemStatus::Running->value)
            ->exists();
    }

    private function owed(AiOperation $operation): int
    {
        return AiOperationItem::query()->where('operation_id', $operation->id)->whereIn('status', AiOperationItemStatus::owed())->count();
    }

    private function pauseFor(AiOperation $operation, AiOperationPauseReason $reason): void
    {
        $operation->forceFill(['state' => AiOperationState::Paused, 'pause_reason' => $reason, 'pause_requested' => false, 'counts' => [...$operation->counts, 'coverage' => OutputCoverage::of($operation)]]);
        $operation->save();
        Log::info('Paused an AI operation', ['operation' => $operation->id, 'reason' => $reason->value]);
    }

    /** The actor still sees the space and may start runs on the operation's surface. */
    private function mayRun(AiOperation $operation): bool
    {
        $space = $operation->space;
        $actor = $operation->actor;

        $target = $space !== null && $actor !== null ? $this->targets->for($operation->scope['type']) : null;

        return $target !== null
            && $this->auth->canInSpace($actor, $space, 'space.view')
            && $this->auth->canInSpace($actor, $space, $target->viewAbility())
            // Content is written straight away, so its actor needs the rights a save needs.
            && ($operation->scope['type'] !== AiChangesetItem::TARGET_CONTENT || $this->auth->canInSpace($actor, $space, $target->editAbility()))
            && app(AiRunPolicy::class)->create($actor, $space, $operation->surface);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validPlan(AiOperation $operation, array $plan): array
    {
        ['plan' => $normalized, 'errors' => $errors] = OperationPlan::validate($operation, $plan);

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($message, $key) => ["plan.{$key}" => [$message]])->all());
        }

        return $normalized;
    }

    /**
     * What a run of the operation sees under `operation`, inside the
     * untrusted-data fence: the approved plan for batches, counts for planning.
     *
     * @return array<string, mixed>
     */
    private function runContext(AiOperation $operation, ?int $batch): array
    {
        return array_filter([
            'kind' => $operation->kind->value,
            'counts' => array_intersect_key($operation->counts, array_flip(['total', 'supported', 'unsupported', 'groups'])),
            'plan' => $batch !== null ? $operation->approved_plan : null,
            'batch' => $batch,
        ], fn ($value) => $value !== null);
    }

    private const LINKED_METADATA = ' This plan also generates page metadata from the translation. A locale\'s `<locale>.<field>.<key>` parts are that metadata. '
        .'For each locale: first finish its translation. Then call get_translation_task again and, when `metadata.ready` is true, write the owed keys with propose_translation_metadata from `draft.texts` only, passing `draft.version_id`. '
        .'Never take metadata from the source language or from text outside that draft, and do not translate those meta keys as units.';

    private function batchInstructions(int $number): string
    {
        return "This run is batch {$number} of an approved AI content operation. The approved plan is in the context under `operation.plan`; "
            .'follow it and do nothing it does not cover. Content tools write to the entry\'s draft right away, and asset and data entry changes wait as proposals for a person; nobody checks in between, and nothing is published. '
            .'Work through each entry under `targets`: read it, then either make the changes the plan calls for, '
            .'or call report_items for it with outcome `unchanged` when the plan needs no change there, or `skipped` with a reason when you cannot do it. '
            .'An entry\'s `done` lists the parts of it already settled; work on the others only. `owed` lists each part (a locale, or a field per language) still owed. '
            .'Every owed part needs a change, or a report_items `outputs` entry with the part, its outcome and a reason: a report for the whole entry does not settle owed parts. '
            .'A tool result with `conflicts` means a person changed those fields after you read them, so they stay open for that person: do not write them again and move on. '
            .'`may_keep` lists owed parts that hold a value the plan allows replacing: write a better one or report them `satisfied`. '
            .'Finish what you can even when a part cannot be done. Touch no other entries and never publish. Finish with a one-line summary.';
    }

    /**
     * Up to `$size` entries for the planning run, taken in turn from each group.
     *
     * @return list<array{id: string, name: string|null, group: string|null}>
     */
    private function sample(AiOperation $operation, int $size): array
    {
        $items = AiOperationItem::query()
            ->where('operation_id', $operation->id)
            ->where('status', AiOperationItemStatus::Pending->value)
            ->orderBy('position')
            ->get(['target_id', 'label', 'group']);

        $groups = $items->groupBy(fn (AiOperationItem $item) => $item->group ?? '')->map->values()->values();
        $sample = [];
        for ($i = 0; \count($sample) < $size && $groups->contains(fn (Collection $group) => $group->has($i)); $i++) {
            foreach ($groups as $group) {
                if ($group->has($i) && \count($sample) < $size) {
                    $sample[] = ['id' => $group[$i]->target_id, 'name' => $group[$i]->label, 'group' => $group[$i]->group];
                }
            }
        }

        return $sample;
    }

    /** The skill revision frozen at creation. */
    private function skill(AiOperation $operation): ?ResolvedSkill
    {
        $ref = $operation->skill_ref;
        $manifest = $ref !== null ? $this->skills->load($ref) : null;

        if ($ref !== null && $manifest === null) {
            throw new AiServiceException('skill_unavailable', 'The skill revision this operation was created with is no longer available.');
        }

        return $manifest ? new ResolvedSkill($manifest, AiScope::from($ref['source']), $ref['owner_id'], $ref['skill_id'], $ref['revision_id']) : null;
    }

    private function config(AiOperation $operation): ?SpaceAiConfig
    {
        return $operation->ai_config_id !== null ? $operation->space->aiConfigs()->find($operation->ai_config_id) : null;
    }

    /**
     * Run a callback with the operation's lock and a fresh copy of it.
     *
     * @template T
     *
     * @param  callable(AiOperation): T  $callback
     * @return T
     *
     * @throws OperationConflict when another worker holds the lock past the wait
     */
    private function locked(AiOperation $operation, callable $callback): mixed
    {
        $lock = Cache::lock("ai-operation:{$operation->id}", 120);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new OperationConflict('The operation is busy. Try again in a moment.', 'busy');
        }

        try {
            return $callback($operation->refresh());
        } finally {
            $lock->release();
        }
    }
}
