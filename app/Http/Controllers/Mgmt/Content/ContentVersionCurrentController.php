<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Http\Request;

class ContentVersionCurrentController extends Controller
{
    public function __invoke(
        Space $space,
        Content $content,
        ContentVersion $version,
        Request $request,
    ) {
        $this->authorize('update', [$content, $space]);

        abort_unless($version->content_id === $content->id, 404);

        $content->current_version_id = $version->id;
        $content->save();

        return response([])->setStatusCode(204);
    }
}
