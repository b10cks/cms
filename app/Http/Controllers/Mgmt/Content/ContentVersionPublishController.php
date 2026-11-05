<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\PublishContentVersion;
use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Http\Response;

class ContentVersionPublishController extends Controller
{
    public function __invoke(Space $space, Content $content, ContentVersion $version, PublishContentVersion $publish): Response
    {
        $this->authorize('publish', [$content, $space]);

        $publish->execute(['version_id' => $version->id], $content, $space, auth()->user());

        return response()->noContent();
    }
}
