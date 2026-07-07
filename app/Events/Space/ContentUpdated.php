<?php

namespace App\Events\Space;

use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ContentUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public Space $space;

    /**
     * Resolved eagerly: space models can't be restored on a queue worker
     * where no space connection is bound.
     *
     * @var array<string, mixed>
     */
    public array $payload;

    public function __construct(Content $content, Space $space)
    {
        $this->space = $space;

        $content->load(['block', 'i18n_children']);
        $content->loadCount(['children']);
        $this->payload = ContentResource::make($content)->resolve();
    }

    public function broadcastOn()
    {
        return [
            new Channel('spaces.' . $this->space->id . '.content'),
        ];
    }

    public function broadcastAs()
    {
        return 'content:updated';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
