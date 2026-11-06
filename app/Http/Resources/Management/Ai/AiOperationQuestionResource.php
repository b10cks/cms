<?php

namespace App\Http\Resources\Management\Ai;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One group of blocked outputs asking the same question. `fingerprint` hashes
 * its exact members: send it back with an answer for the whole group.
 *
 * @property array{id: string, question: string|null, note: string|null, fingerprint: string, outputs: int, entries: int} $resource
 */
class AiOperationQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
