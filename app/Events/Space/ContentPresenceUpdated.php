<?php

namespace App\Events\Space;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContentPresenceUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $spaceId,
        public string $contentId,
        public array $user,
        public string $action
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('presence-spaces.'.$this->spaceId.'.content'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'content:presence';
    }
}
