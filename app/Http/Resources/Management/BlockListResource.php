<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Block;
use Illuminate\Http\Request;

/**
 * @mixin Block
 */
class BlockListResource extends BlockResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'templates_count' => $this->whenCounted('templates'),
        ]);
    }
}
