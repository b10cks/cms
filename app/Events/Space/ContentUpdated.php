<?php

namespace App\Events\Space;

use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ContentUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public Content $content;
    public Space $space;

    public function __construct(Content $content, Space $space)
    {
        $this->content = $content;
        $this->space = $space;
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
        $this->content->load(['block', 'i18n_children']);
        $this->content->loadCount(['children']);

        return ContentResource::make($this->content)->resolve();
    }
}
