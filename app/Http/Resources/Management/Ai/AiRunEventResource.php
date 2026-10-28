<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiRunEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiRunEvent */
class AiRunEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['type' => $this->type, 'run_id' => $this->run_id, 'seq' => $this->seq] + $this->payload;
    }
}
