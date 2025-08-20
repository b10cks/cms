<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\OpenAiService;
use Illuminate\Http\Request;

class MetaTagsController extends Controller
{
    use SpaceFromQuery;

    public function __invoke(Request $request, OpenAiService $service)
    {
        $service->setSpace($this->getSpaceFromQuery());
        $response = $service->metaTags($request->json('context'));

        return [
            'data' => $response
        ];
    }
}
