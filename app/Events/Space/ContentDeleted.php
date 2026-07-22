<?php

namespace App\Events\Space;

use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ContentDeleted implements ShouldBroadcast
{
    use SerializesModels;

    public Space $space;

    /**
     * Resolved eagerly: space models can't be restored on a queue worker
     * where no space connection is bound (and the content is soft-deleted).
     *
     * Identifiers only — the full content resource blows past Pusher's 10KB
     * message limit and nothing needs the body of a deleted content.
     *
     * @var array<string, mixed>
     */
    public array $payload;

    public function __construct(Content $content, Space $space)
    {
        $this->space = $space;
        $this->payload = [
            'id' => $content->id,
            'parent_id' => $content->parent_id,
            'i18n_parent_id' => $content->i18n_parent_id,
        ];
    }

    public function broadcastOn()
    {
        return [
            new Channel('spaces.'.$this->space->id.'.content'),
        ];
    }

    public function broadcastAs()
    {
        return 'content:deleted';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
