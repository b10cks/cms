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

    private string $modelKey;

    private string $modelBaseClass;

    public function __construct(
        protected Space $space,
        protected string $resourceType,
        protected string $action,
        Model $model,
    ) {
        $this->modelKey = $model->getKey();
        $this->modelBaseClass = class_basename($model);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('spaces.'.$this->space->id.'.'.$this->resourceType),
        ];
    }

    public function broadcastAs(): string
    {
        return Str::snake($this->modelBaseClass).':'.$this->action;
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->modelKey,
            'action' => $this->action,
        ];
    }
}
