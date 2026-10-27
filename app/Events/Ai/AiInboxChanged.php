<?php

namespace App\Events\Ai;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A changeset or a background run of the space changed. IDs only; inbox
 * clients refetch.
 */
class AiInboxChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $spaceId,
        public readonly ?string $changesetId = null,
        public readonly ?string $runId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("spaces.{$this->spaceId}.ai")];
    }

    public function broadcastAs(): string
    {
        return 'ai_inbox:changed';
    }

    public function broadcastWith(): array
    {
        return ['changeset_id' => $this->changesetId, 'run_id' => $this->runId];
    }
}
