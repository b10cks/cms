<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\PublishContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpsertContentRequest;
use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Facades\Log;

class ContentPublishController extends Controller
{
    public function __invoke(Space $space, Content $content, UpsertContentRequest $request, PublishContent $action): ContentResource
    {
        $this->authorize('publish', [$content, $space]);

        $data = $request->validated();
        $action->execute($data, $content, $request->user());

//        if (!$content->save()) {
//            Log::error('Failed to publish content', ['content_id' => $content->id, 'space_id' => $space->id]);
//            abort(500, 'Failed to publish content');
//        }

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }

    /**
     * Unpublish the specified content item.
     */
    public function unpublish(Space $space, Content $content): ContentResource
    {
        $this->authorize('publish', [$content, $space]);

        $content->published_at = null;

        if (!$content->save()) {
            Log::error('Failed to unpublish content', ['content_id' => $content->id, 'space_id' => $space->id]);
            abort(500, 'Failed to unpublish content');
        }

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }
}
