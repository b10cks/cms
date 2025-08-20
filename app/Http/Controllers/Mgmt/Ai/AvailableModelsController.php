<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Filters\Mgmt\AiModelFilter;
use App\Http\Resources\Management\AiModelResource;
use App\Models\Management\AiModel;
use Illuminate\Http\Request;

class AvailableModelsController
{
    public function __invoke(Request $request)
    {
        $models = AiModel::filter(AiModelFilter::fromRequest($request))
            ->get();

        return [
            'data' => AiModelResource::collection($models)
        ];
    }
}
