<?php

namespace App\Events\Space;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * A collection's *content* changed — manual membership edits or smart-rule
 * updates — without the collection row itself necessarily being touched.
 * Fired from the same choke point that marks packages of the collection
 * stale, so both consumers stay in lockstep.
 *
 * Identifiers only: listeners refetch the derived membership anyway.
 */
class AssetCollectionContentChanged implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected string $spaceId,
        protected string $collectionId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('spaces.'.$this->spaceId.'.assets'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'asset_collection:content_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->collectionId,
        ];
    }
}
