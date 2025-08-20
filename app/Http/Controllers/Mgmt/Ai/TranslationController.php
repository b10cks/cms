<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\OpenAiService;
use Illuminate\Http\Request;

class TranslationController extends Controller
{
    use SpaceFromQuery;

    public function __invoke(Request $request, OpenAiService $service)
    {
        $service->setSpace($this->getSpaceFromQuery());
        $response = $service->translate($request->string('source'), $request->string('target'), $request->json('fields'));

        return [
            'data' => $response
        ];
    }
}
