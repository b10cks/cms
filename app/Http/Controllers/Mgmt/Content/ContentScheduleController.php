<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\SchedulePublishContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ScheduleContentRequest;
use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;

class ContentScheduleController extends Controller
{
    public function __invoke(Space $space, Content $content, ScheduleContentRequest $request, SchedulePublishContent $action): ContentResource
    {
        $this->authorize('schedule', [$content, $space]);

        $data = $request->validated();
        $action->execute($data, $content, $space, $request->user());

        //        if (!$content->save()) {
//            Log::error('Failed to publish content', ['content_id' => $content->id, 'space_id' => $space->id]);
//            abort(500, 'Failed to publish content');
//        }

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }
}
