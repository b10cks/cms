<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Space\Redirect
 */
class RedirectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'status_code' => $this->status_code,
        ];
    }
}
