<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContentResourceCollection extends ResourceCollection
{
    public function toArray(Request $request)
    {
        $this->additional([
            'ts' => $request->space->ts
        ]);

        return $this->collection->map(function ($content) use ($request) {
            return new ContentResource($content, $request);
        })->all();
    }

}
