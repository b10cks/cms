<?php

namespace App\Events\Ai;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A background run stored new events. Carries IDs only (Reverb drops messages
 * over 10KB); the client fetches `GET /runs/{run}/events?after={seq}`.
 */
class AiRunEventsAvailable implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $actorId,
        public readonly string $runId,
        public readonly int $seq,
        public readonly string $type,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->actorId)];
    }

    public function broadcastAs(): string
    {
        return 'ai_run:events';
    }

    public function broadcastWith(): array
    {
        return ['run_id' => $this->runId, 'seq' => $this->seq, 'type' => $this->type];
    }
}
