<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Jobs\Content\BulkPublishContentJob;
use App\Models\Management\Space;
use App\Services\Content\ContentBulkPublishRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publishes or unpublishes a selection of entries in a queued run. An entry
 * that fails does not roll back the others, so the run lists both outcomes.
 */
class ContentBulkPublishController extends Controller
{
    private const MAX_IDS = 500;

    /**
     * Queues a bulk publish
     *
     * Starts a run that publishes each entry on its own and returns it with
     * status 202. Poll the run until `state` is `completed` or `failed`;
     * `succeeded`, `failed` and `index_failed` (published, but not in search)
     * fill in as it goes. Only one run per space at a time, a second one gets 409.
     */
    public function publish(Request $request, Space $space): JsonResponse
    {
        $this->authorizeSpace($space, 'content.publish');

        $data = $request->validate($this->rules() + [
            'message' => 'sometimes|nullable|string|max:255',
        ]);

        return $this->start($request, $space, 'publish', $data['ids'], $data['message'] ?? null);
    }

    /**
     * Queues a bulk unpublish
     *
     * Same run as bulk publish, taking each entry offline.
     */
    public function unpublish(Request $request, Space $space): JsonResponse
    {
        $this->authorizeSpace($space, 'content.publish');

        $data = $request->validate($this->rules());

        return $this->start($request, $space, 'unpublish', $data['ids']);
    }

    /**
     * Shows a bulk publish run
     *
     * Progress and per-entry outcome of a bulk publish or unpublish run. Runs
     * stay readable for a day.
     */
    public function show(Space $space, string $run): JsonResponse
    {
        $this->authorizeSpace($space, 'content.publish');

        $found = ContentBulkPublishRun::find($space->id, $run);
        abort_unless($found, 404);

        return response()->json(['data' => $found->toArray()]);
    }

    private function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:'.self::MAX_IDS,
            'ids.*' => 'required|string|distinct',
        ];
    }

    /**
     * @param  'publish'|'unpublish'  $action
     * @param  list<string>  $ids
     */
    private function start(Request $request, Space $space, string $action, array $ids, ?string $message = null): JsonResponse
    {
        $lock = ContentBulkPublishRun::lock($space);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Another bulk publish is still running in this space. Wait for it to finish and try again.',
            ], 409);
        }

        $run = ContentBulkPublishRun::start($space, $action, count($ids));

        try {
            BulkPublishContentJob::dispatch($space, $request->user(), $run->id(), $lock->owner(), $action, array_values($ids), $message);
        } catch (\Throwable $e) {
            $lock->release();
            throw $e;
        }

        // A sync queue has already finished the run by now; reread it so the
        // response carries the outcome.
        $current = ContentBulkPublishRun::find($space->id, $run->id()) ?? $run;

        return response()->json(['data' => $current->toArray()], 202);
    }
}
