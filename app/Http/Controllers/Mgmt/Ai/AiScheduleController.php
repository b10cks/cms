<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SaveAiScheduleRequest;
use App\Http\Resources\Management\Ai\AiScheduleResource;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Services\Ai\Schedules\ScheduleRunner;
use App\Services\Ai\Skills\ResolvedSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Scheduled agents of a space. Managing them needs `ai.manage`; saving a
 * schedule makes the saver the user its runs act as.
 */
class AiScheduleController extends Controller
{
    public function index(Space $space, ScheduleRunner $runner): JsonResponse
    {
        $this->authorizeSpace($space, 'ai.manage');

        $schedules = AiSchedule::query()->where('space_id', $space->id)->with(['creator', 'lastRun'])->orderBy('name')->get();
        $skills = [];
        foreach (array_keys(ScheduleRunner::SURFACES) as $type) {
            $skills[$type] = array_values(array_map(
                fn (ResolvedSkill $skill) => ['name' => $skill->manifest->name, 'description' => $skill->manifest->description],
                $runner->eligibleSkills($space, $type),
            ));
        }

        return response()->json(['data' => AiScheduleResource::collection($schedules), 'skills' => $skills]);
    }

    public function store(SaveAiScheduleRequest $request, Space $space, ScheduleRunner $runner): AiScheduleResource
    {
        $this->authorizeSpace($space, 'ai.manage');
        $attributes = $request->validated();
        $this->ensureSkill($runner, $space, $attributes['skill'], $attributes['scope']['type']);

        $schedule = AiSchedule::create([
            ...$attributes,
            'space_id' => $space->id,
            'created_by' => $request->user()->id,
            'enabled' => $request->boolean('enabled', true),
        ]);

        return new AiScheduleResource($schedule->load(['creator', 'lastRun']));
    }

    public function update(SaveAiScheduleRequest $request, Space $space, string $schedule, ScheduleRunner $runner): AiScheduleResource
    {
        $this->authorizeSpace($space, 'ai.manage');
        $model = AiSchedule::query()->where('space_id', $space->id)->findOrFail($schedule);
        $model->fill($request->validated());
        $this->ensureSkill($runner, $space, $model->skill, (string) ($model->scope['type'] ?? ''));

        // Whoever saves the schedule is who its runs act as; saving clears a pause.
        $model->created_by = $request->user()->id;
        if ($model->enabled) {
            $model->paused_reason = null;
        }
        $model->save();

        return new AiScheduleResource($model->load(['creator', 'lastRun']));
    }

    public function destroy(Space $space, string $schedule): JsonResponse
    {
        $this->authorizeSpace($space, 'ai.manage');
        AiSchedule::query()->where('space_id', $space->id)->findOrFail($schedule)->delete();

        return response()->json(null, 204);
    }

    private function ensureSkill(ScheduleRunner $runner, Space $space, string $skill, string $type): void
    {
        if (! isset($runner->eligibleSkills($space, $type)[$skill])) {
            throw ValidationException::withMessages(['skill' => ['This skill cannot run on a schedule over this scope: it needs the matching bulk surface and propose_update.']]);
        }
    }
}
