<?php

namespace App\Events\Ai;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A thread's status or activity may have changed. The thread ID only; clients
 * refetch through the authorised endpoints.
 */
class AiThreadChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $spaceId,
        public readonly string $threadId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("spaces.{$this->spaceId}.ai")];
    }

    public function broadcastAs(): string
    {
        return 'ai_thread:changed';
    }

    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId];
    }
}
