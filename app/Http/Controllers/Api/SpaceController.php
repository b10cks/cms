<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Api\SpaceResource;
use Illuminate\Http\Request;

/**
 * Get the metadata of the token's space, including the current content
 * revision and the enabled languages. Clients use the revision to pin
 * cache-friendly request URLs.
 */
class SpaceController
{
    public function __invoke(Request $request): SpaceResource
    {
        $space = app('currentSpace');

        return new SpaceResource($space);
    }
}
