<?php

namespace App\Events\Space;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * A data source's *entries* changed wholesale — bulk import, replacement
 * delete, bulk translation — with the per-entry broadcasts muted for the
 * run (see BroadcastsSpaceModelEvents::withoutBroadcasts). One event stands
 * in for the thousands that were suppressed.
 *
 * Identifiers only: listeners refetch the entry caches anyway.
 */
class DataSourceContentChanged implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected string $spaceId,
        protected string $dataSourceId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('spaces.'.$this->spaceId.'.data_sources'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'data_source:content_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->dataSourceId,
        ];
    }
}
