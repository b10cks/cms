<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentVersionFilter;
use App\Http\Resources\Management\ContentVersionListResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContentVersionPublishController extends Controller
{
    public function __invoke(Space $space, Content $content, ContentVersion $version, Request $request)
    {
        $this->authorize('publish', [$content, $space]);

        $content->published_version_id = $version->id;
        $content->published_at = now();
        $content->save();
        $version->published_at = $version->published_at ?? now();
        $version->save();

        return response([])->setStatusCode(204);
    }
}
