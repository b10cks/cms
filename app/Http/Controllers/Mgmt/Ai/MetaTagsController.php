<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\OpenAiService;
use Illuminate\Http\Request;

class MetaTagsController extends Controller
{
    public function __invoke(Request $request, OpenAiService $service)
    {
        $response = $service->metaTags($request->json('context'));

        return [
            'data' => $response
        ];
    }
}
