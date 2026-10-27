<?php

namespace App\Services\Ai\Schedules;

use App\Enums\AiRunState;
use App\Events\Ai\AiInboxChanged;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiRun;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\ResolvedSkill;
use App\Services\Ai\Skills\SkillResolver;
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
    ) {}

    /**
     * Skills a schedule over this scope type can use: they run on its bulk
     * surface and may propose updates, so the output is a changeset.
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
                && ($skill->manifest->tools === null || \in_array(ProposeUpdateTool::NAME, $skill->manifest->tools, true)),
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
            $entries = $this->scope->resolve($type, (array) ($schedule->scope['filters'] ?? []));
            if ($entries === []) {
                return null;
            }

            if ($this->backgroundRuns($space) >= (int) config('ai.runs.max_concurrent_background')) {
                Log::info('Skipped a scheduled AI run: the space runs its limit of background tasks', ['schedule' => $schedule->id]);

                return null;
            }

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
            ));
        } finally {
            $restore();
        }

        $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
        $schedule->forceFill(['last_run_id' => $run->id])->save();
        broadcast(new AiInboxChanged($space->id, runId: $run->id));
        RunAgentJob::dispatch($run->id);

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
