<?php

namespace App\Events\Space;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Many entries changed at once, like a translation import, with the per-row
 * broadcasts muted for the run (see Content::withoutBroadcasts). One event
 * stands in for the hundreds that were suppressed.
 *
 * No payload: listeners refetch the menu and content caches.
 */
class ContentsChanged implements ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(
        protected string $spaceId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('spaces.'.$this->spaceId.'.content'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'content:changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
