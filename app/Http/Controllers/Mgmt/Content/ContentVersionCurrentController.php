<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;

class ContentVersionCurrentController extends Controller
{
    public function __invoke(
        Space $space,
        Content $content,
        ContentVersion $version,
        Request $request,
        ContentSchemaValidator $contentSchemaValidator,
    )
    {
        $this->authorize('update', [$content, $space]);
        $validation = $contentSchemaValidator->validateVersion($space, $content, $version);

        if (! $validation->isValid()) {
            throw ValidationException::withMessages($validation->errors);
        }

        $content->current_version_id = $version->id;
        $content->save();

        return response([])->setStatusCode(204);
    }
}
