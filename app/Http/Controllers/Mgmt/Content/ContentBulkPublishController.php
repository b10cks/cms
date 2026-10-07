<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\PublishContent;
use App\Actions\Content\UnpublishContent;
use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Audit\AuditActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Publishes or unpublishes a selection of entries one by one. An entry that
 * fails does not roll back the others, so the response lists both outcomes.
 */
class ContentBulkPublishController extends Controller
{
    private const MAX_IDS = 500;

    public function publish(Request $request, Space $space, PublishContent $action): JsonResponse
    {
        $this->authorizeSpace($space, 'content.publish');

        $data = $request->validate($this->rules() + [
            'message' => 'sometimes|nullable|string|max:255',
        ]);
        $payload = Arr::only($data, ['message']);

        return $this->run($request, $data['ids'], 'published', function (Content $content) use ($action, $payload, $space, $request) {
            $action->execute($payload, $content, $space, $request->user());
        });
    }

    public function unpublish(Request $request, Space $space, UnpublishContent $action): JsonResponse
    {
        $this->authorizeSpace($space, 'content.publish');

        $data = $request->validate($this->rules());

        return $this->run($request, $data['ids'], 'unpublished', function (Content $content) use ($action, $space) {
            $action->execute($content, $space);
        });
    }

    private function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:'.self::MAX_IDS,
            'ids.*' => 'required|string|distinct',
        ];
    }

    /**
     * @param  list<string>  $ids
     * @param  callable(Content): void  $operation
     */
    private function run(Request $request, array $ids, string $auditEvent, callable $operation): JsonResponse
    {
        $contents = Content::query()->whereIn('id', $ids)->get()->keyBy('id');
        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $content = $contents->get($id);

            if (! $content) {
                $failed[] = ['id' => $id, 'name' => null, 'message' => trans('validation.exists', ['attribute' => 'id'])];

                continue;
            }

            try {
                $content->withoutAudit();
                $operation($content);
                $content->auditSpaceEvent($auditEvent, AuditActor::user($request->user()));
                $succeeded[] = $id;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $id, 'name' => $content->name, 'message' => Arr::first(Arr::flatten($e->errors()))];
            } catch (\Throwable $e) {
                report($e);
                $failed[] = ['id' => $id, 'name' => $content->name, 'message' => 'Unexpected error'];
            }
        }

        return response()->json(['data' => ['succeeded' => $succeeded, 'failed' => $failed]]);
    }
}
