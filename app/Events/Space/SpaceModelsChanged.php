<?php

namespace App\Events\Space;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * A bulk operation (import, bulk assign, slug propagation) changed many rows
 * of one model with the per-row broadcasts muted. One event stands in for the
 * hundreds that were suppressed.
 *
 * Identifiers only: listeners refetch the model's lists anyway.
 */
class SpaceModelsChanged implements ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(
        protected string $spaceId,
        protected string $resourceType,
        protected string $model,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('spaces.'.$this->spaceId.'.'.$this->resourceType),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->model.':bulk_changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
