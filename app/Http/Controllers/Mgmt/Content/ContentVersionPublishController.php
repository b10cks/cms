<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentVersionFilter;
use App\Http\Resources\Management\ContentVersionListResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Search\SearchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;

class ContentVersionPublishController extends Controller
{
    public function __construct(
        protected SearchService $searchService,
        protected ContentSchemaValidator $contentSchemaValidator,
    ) {
    }

    public function __invoke(Space $space, Content $content, ContentVersion $version, Request $request)
    {
        $this->authorize('publish', [$content, $space]);
        $validation = $this->contentSchemaValidator->validateVersion($space, $content, $version, 'publish');

        if (!$validation->isValid()) {
            throw ValidationException::withMessages($validation->errors);
        }

        $content->published_version_id = $version->id;
        $content->published_at = now();
        $content->save();
        $version->published_at ??= now();
        $version->save();

        $space->touch('content_updated_at');

        $content->load('published_version');
        $this->searchService->indexContent($content, $space);

        return response([])->setStatusCode(204);
    }
}
