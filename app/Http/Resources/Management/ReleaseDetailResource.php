<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Release;
use Illuminate\Http\Request;

/**
 * @mixin Release
 */
class ReleaseDetailResource extends ReleaseResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'versions' => $this->whenLoaded(
                'versions',
                fn() => ContentVersionListResource::collection($this->versions)
            ),
        ]);
    }
}
