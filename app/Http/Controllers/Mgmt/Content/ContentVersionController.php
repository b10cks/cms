<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentVersionFilter;
use App\Http\Resources\Management\ContentVersionListResource;
use App\Http\Resources\Management\ContentVersionResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContentVersionController extends Controller
{
    public function index(Space $space, Content $content, Request $request): ResourceCollection
    {
        $this->authorize('viewHistory', [$content, $space]);
        $versions = ContentVersion::where('content_id', $content->id)
            ->select([
                'id', 'external_id', 'message', 'content_id', 'parent_id', 'release_id',
                'created_by_id', 'published_by_id', 'published_at', 'scheduled_at', 'created_at',
            ])
            ->with(['createdBy', 'release', 'publishedBy'])
            ->filter(ContentVersionFilter::fromRequest($request))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50, 200));

        return ContentVersionListResource::collection($versions);
    }

    public function show(Space $space, Content $content, ContentVersion $version): ContentVersionResource
    {
        $this->authorize('viewHistory', [$content, $space]);
        $version->load(['parent', 'release']);

        return new ContentVersionResource($version);
    }

    public function update(Request $request, Space $space, Content $content, ContentVersion $version): ContentVersionResource
    {
        $this->authorize('viewHistory', [$content, $space]);
        abort_if(auth()->id() !== $version->created_by_id, 403, 'You are not allowed to view this version.');
        $version->message = $request->string('message');
        $version->save();
        $version->load(['parent', 'release']);

        return new ContentVersionResource($version);
    }
}
