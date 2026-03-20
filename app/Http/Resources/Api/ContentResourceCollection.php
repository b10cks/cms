<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContentResourceCollection extends ResourceCollection
{
    public function toArray(Request $request)
    {
        $this->additional([
            'rv' => app('currentSpace')->rv
        ]);

        return $this->collection->map(
            fn($content) => $content instanceof ContentResource ? $content : new ContentResource($content)
        )->all();
    }

}
