<?php

namespace App\Services\Ai\Schedules;

use App\Enums\AiOperationKind;
use App\Enums\AiOperationState;
use App\Enums\AiRunState;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Operations\CreateOperation;
use App\Services\Ai\Operations\OperationPlan;
use App\Services\Ai\Operations\ScopeTooLarge;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\ResolvedSkill;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Ai\Tools\ProposeTranslationTool;
use App\Services\Ai\Tools\ProposeUpdateTool;
use App\Services\Auth\AuthorizationService;
use App\Support\SpaceContext;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Starts the background run of a due schedule. Every run rechecks that the
 * creator may still manage the space's AI and read the scope; a schedule
 * that lost either pauses before any provider call.
 *
 * A scope larger than one run may take becomes an operation instead,
 * approved by the saved schedule: its name is the plan, its `max_usd` the
 * shared budget. A tick is skipped while the schedule's last operation is
 * still running or paused, so budgets never stack up unattended.
 */
class ScheduleRunner
{
    /** The bulk surface a scope type runs on. */
    public const SURFACES = [
        'content' => 'bulk:content',
        'data_entry' => 'bulk:data_entries',
        'asset' => 'bulk:assets',
    ];

    public function __construct(
        private readonly AuthorizationService $auth,
        private readonly SkillResolver $skills,
        private readonly ChangeTargets $targets,
        private readonly EntryScope $scope,
        private readonly AiRunService $runs,
        private readonly AiThreads $threads,
        private readonly AiOperations $operations,
    ) {}

    /**
     * Skills a schedule over this scope type can use: they run on its bulk
     * surface and may write updates or translations, so the output is a changeset.
     *
     * @return array<string, ResolvedSkill>
     */
    public function eligibleSkills(Space $space, string $type): array
    {
        $surface = self::SURFACES[$type] ?? null;

        return array_filter(
            $this->skills->resolve($space),
            fn (ResolvedSkill $skill) => $surface !== null
                && $skill->manifest->matches($surface)
                && ($skill->manifest->tools === null || array_intersect([ProposeUpdateTool::NAME, ProposeTranslationTool::NAME], $skill->manifest->tools) !== []),
        );
    }

    public static function isDue(AiSchedule $schedule, Carbon $now): bool
    {
        return (new CronExpression($schedule->cron))->isDue($now)
            && ($schedule->last_run_at === null || $schedule->last_run_at->lt($now->copy()->startOfMinute()));
    }

    public static function nextRunAt(AiSchedule $schedule): ?Carbon
    {
        return $schedule->enabled ? Carbon::instance((new CronExpression($schedule->cron))->getNextRunDate(now())) : null;
    }

    /**
     * @return AiRun|null the started run; null when paused, nothing matched or the space is busy
     */
    public function run(AiSchedule $schedule): ?AiRun
    {
        $space = $schedule->space;
        $creator = $schedule->creator;
        $type = (string) ($schedule->scope['type'] ?? '');

        if (! $space || ! $creator || ! $this->mayRun($creator, $space, $type)) {
            $this->pause($schedule, AiSchedule::PAUSED_ACCESS);

            return null;
        }

        $skill = $this->eligibleSkills($space, $type)[$schedule->skill] ?? null;
        if (! $skill) {
            $this->pause($schedule, AiSchedule::PAUSED_SKILL);

            return null;
        }

        $restore = SpaceContext::enter($space);

        try {
            if (AiOperation::query()->where('schedule_id', $schedule->id)->whereIn('state', [AiOperationState::Running->value, AiOperationState::Paused->value])->exists()) {
                Log::info('Skipped a scheduled AI run: its last operation has not finished', ['schedule' => $schedule->id]);

                return null;
            }

            $filters = (array) ($schedule->scope['filters'] ?? []);
            if ($this->backgroundRuns($space) >= (int) config('ai.runs.max_concurrent_background')) {
                Log::info('Skipped a scheduled AI run: the space runs its limit of background tasks', ['schedule' => $schedule->id]);

                return null;
            }

            if ($this->scope->overflows($type, $filters)) {
                return $this->runAsOperation($schedule, $space, $creator, $skill, $type, $filters);
            }

            $entries = $this->scope->resolve($type, $filters);
            if ($entries === []) {
                return null;
            }

            // Each run appends a turn to the schedule's standing thread.
            $run = $this->runs->start(new StartRun(
                space: $space,
                actor: $creator,
                surface: self::SURFACES[$type],
                skill: $skill,
                prompt: $schedule->name,
                context: ['targets' => ['type' => $type, 'entries' => array_map(fn (array $entry) => ['id' => $entry['id'], 'name' => $entry['name']], $entries)]],
                instructions: StartRun::TARGET_INSTRUCTIONS,
                targets: ['type' => $type, 'ids' => array_column($entries, 'id')],
                maxUsd: $schedule->max_usd,
                conversationId: $this->threads->forSchedule($schedule, self::SURFACES[$type])->id,
            ));
            $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
        } finally {
            $restore();
        }

        $schedule->forceFill(['last_run_id' => $run->id])->save();
        RunAgentJob::dispatch($run->space_id, $run->id);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function runAsOperation(AiSchedule $schedule, Space $space, User $creator, ResolvedSkill $skill, string $type, array $filters): ?AiRun
    {
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $root = $filters['parent_id'] ?? $filters['folder_id'] ?? null;

        // The `missing_*` filters of data entries and assets run in PHP and cannot walk 10,000 entries.
        if (array_diff(array_keys($filters), [...EntryScope::RECURSIVE_FILTERS, 'parent_id', 'folder_id']) !== []) {
            $this->pause($schedule, AiSchedule::PAUSED_SCOPE);

            return null;
        }

        try {
            $operation = $this->operations->create(new CreateOperation(
                space: $space,
                actor: $creator,
                kind: AiOperationKind::General,
                prompt: $schedule->name,
                scope: ['type' => $type, 'root_id' => $root, 'recursive' => false, 'filters' => $filters],
                skill: $skill,
                conversationId: $this->threads->forSchedule($schedule, self::SURFACES[$type])->id,
                scheduleId: $schedule->id,
            ));
        } catch (ScopeTooLarge) {
            $this->pause($schedule, AiSchedule::PAUSED_SCOPE);

            return null;
        }

        $plan = OperationPlan::validate($operation, ['summary' => $schedule->name])['plan'];
        $operation->forceFill(['plan' => $plan])->save();
        $this->operations->approve($operation, $creator, OperationPlan::hash($plan), (float) $schedule->max_usd);

        $run = $operation->runs()->oldest()->first();
        $schedule->forceFill(['last_run_id' => $run?->id])->save();

        return $run;
    }

    private function mayRun(User $creator, Space $space, string $type): bool
    {
        if (! \in_array($type, ChangeTargets::TYPES, true)) {
            return false;
        }

        return $this->auth->canInSpace($creator, $space, 'ai.manage')
            && $this->auth->canInSpace($creator, $space, $this->targets->for($type)->viewAbility());
    }

    private function pause(AiSchedule $schedule, string $reason): void
    {
        $schedule->forceFill(['enabled' => false, 'paused_reason' => $reason])->save();
        Log::info('Paused an AI schedule', ['schedule' => $schedule->id, 'reason' => $reason]);
    }

    private function backgroundRuns(Space $space): int
    {
        return AiRun::query()
            ->where('space_id', $space->id)
            ->where('mode', AiRun::MODE_BACKGROUND)
            ->where('state', AiRunState::Running->value)
            ->count();
    }
}
