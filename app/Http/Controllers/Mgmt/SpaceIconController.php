<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Space\UpdateIconRequest;
use App\Http\Resources\Management\SpaceResource;
use App\Models\Management\Space;
use App\Services\Image\ImageUploadService;

class SpaceIconController extends Controller
{
    public function __invoke(UpdateIconRequest $request, Space $space, ImageUploadService $uploadService)
    {
        $uploadService->uploadForModel(
            $space,
            $request->file('icon'),
            'icon',
            'spaces/icons'
        );

        return new SpaceResource($space->fresh());
    }
}
