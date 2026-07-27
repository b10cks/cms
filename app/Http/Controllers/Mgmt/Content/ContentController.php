<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\CreateContent;
use App\Actions\Content\CreateContentFamily;
use App\Actions\Content\DeleteContent;
use App\Actions\Content\UpdateContent;
use App\Actions\Content\UpdateContentFamily;
use App\Exceptions\ContentVersionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentFilter;
use App\Http\Requests\Content\UpsertContentRequest;
use App\Http\Resources\Management\ContentResource;
use App\Http\Resources\Management\ContentVersionResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class ContentController extends Controller
{
    /**
     * Display a listing of the content items.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [Content::class, $space]);

        $content = Content::filter(ContentFilter::fromRequest($request))
            ->paginate();

        ContentResource::preResolve($space, $content->items());

        return ContentResource::collection($content);
    }

    /**
     * Store a newly created content item in storage.
     */
    public function store(
        UpsertContentRequest $request,
        Space $space,
        CreateContent $action,
        CreateContentFamily $familyAction,
    ): ContentResource
    {
        $this->authorize('create', [Content::class, $space]);

        $data = $request->validated();
        if (($data['translations'] ?? []) !== []) {
            $content = $familyAction->execute($data, $space, $request->user());
        } else {
            $content = new Content();
            $action->execute($data, $content, $space, $request->user());

            if (!$content->save()) {
                Log::error('Failed to create content', ['space_id' => $space->id]);
                abort(500, 'Failed to create content');
            }
        }
        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }

    /**
     * Display the specified content item.
     */
    public function show(Space $space, Content $content): ContentResource
    {
        $this->authorize('view', [$content, $space]);

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }

    /**
     * Update the specified content item in storage.
     */
    public function update(
        UpsertContentRequest $request,
        Space $space,
        Content $content,
        UpdateContent $action,
        UpdateContentFamily $familyAction,
    ): ContentResource|JsonResponse
    {
        $this->authorize('update', [$content, $space]);

        $data = $request->validated();
        try {
            if (($data['translations'] ?? []) !== []) {
                $content = $familyAction->execute($data, $content, $space, $request->user());
            } else {
                $action->execute($data, $content, $space, $request->user());
            }
        } catch (ContentVersionConflictException $e) {
            $e->currentVersion->loadMissing('createdBy', 'parent');
            return response()->json([
                'conflict' => true,
                'current_version' => new ContentVersionResource($e->currentVersion),
                'current_content' => $e->currentVersion->content,
            ], 409);
        }

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);
//        broadcast(new ContentUpdated($content, $space));

        return new ContentResource($content);
    }

    /**
     * Remove the specified content item from storage.
     */
    public function destroy(Space $space, Content $content, DeleteContent $action): JsonResponse
    {
        $this->authorize('delete', [$content, $space]);

        try {
            $action->execute($content, $space, auth()->user());
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete content', [
                'content_id' => $content->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the content',
            ], 500);
        }
    }
}
