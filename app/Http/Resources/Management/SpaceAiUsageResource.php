<?php

namespace App\Http\Resources\Management;

use App\Services\Ai\Dto\AiUsageDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiUsageDto */
class SpaceAiUsageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
