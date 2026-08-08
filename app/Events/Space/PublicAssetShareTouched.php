<?php

namespace App\Events\Space;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * Bare "something about this share changed" ping for the public share page
 * (/share/{space}/{token}).
 *
 * The channel is public by design — the viewer is anonymous, and the token in
 * the channel name is the same capability the page URL already carries. That
 * makes the payload the security boundary: it MUST stay empty. No asset data,
 * no collection data, no user identity, no share state — the page refetches
 * through the public API, which re-checks accessibility (revoked/expired
 * shares answer with the same plain 404 the page already renders as
 * unavailable). No presence either: it would leak who is viewing.
 */
class PublicAssetShareTouched implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        protected string $spaceId,
        protected string $token,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('public-share.'.$this->spaceId.'.'.$this->token),
        ];
    }

    public function broadcastAs(): string
    {
        return 'share:updated';
    }

    /**
     * @return array<never, never>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
