<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Api\SpaceResource;
use Illuminate\Http\Request;

class SpaceController
{
    public function __invoke(Request $request): SpaceResource
    {
        $space = app('currentSpace');

        return new SpaceResource($space);
    }
}
