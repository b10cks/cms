<?php

namespace App\Events\Space;

use App\Models\Management\Space;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SpaceModelChanged implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        protected Model $model,
        protected Space $space,
        protected string $resourceType,
        protected string $action
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('spaces.' . $this->space->id . '.' . $this->resourceType),
        ];
    }

    public function broadcastAs(): string
    {
        return Str::snake(class_basename($this->model)) . ':' . $this->action;
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->model->getKey(),
            'action' => $this->action,
        ];
    }
}
