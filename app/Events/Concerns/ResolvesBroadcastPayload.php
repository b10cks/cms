<?php

namespace App\Events\Concerns;

use Illuminate\Http\Resources\Json\JsonResource;

trait ResolvesBroadcastPayload
{
    /**
     * Fully materialise a resource into plain data.
     *
     * JsonResource::resolve() only expands the outermost resource; nested ones
     * stay as resource objects until the payload is encoded. For a broadcast
     * that encoding happens on the queue worker, where no space connection is
     * bound and the model can no longer be read. Encoding here, while the space
     * context is still active, flattens everything up front.
     *
     * Decoded as objects rather than assoc arrays so an empty `{}` does not
     * turn into `[]` when the payload is re-encoded for the wire.
     *
     * @return array<string, mixed>
     */
    protected function resolveBroadcastPayload(JsonResource $resource): array
    {
        return (array) json_decode(
            json_encode($resource, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
