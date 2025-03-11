<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class ModelChangedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @var class-string<JsonResource>
     */
    protected string $resourceClass;

    /**
     * @var string
     */
    protected string $action;

    /**
     * Create a new event instance.
     *
     * @param Model $model The model that was changed
     * @param class-string<JsonResource> $resourceClass The resource class to use for transformation
     * @param string $action The action that occurred (created, updated, deleted, etc.)
     * @param string $channel The channel type (presence, private, public)
     */
    public function __construct(
        protected Model  $model,
        string           $resourceClass,
        string           $action = 'changed',
        protected string $channel = 'presence'
    )
    {
        $this->validateResourceClass($resourceClass);
        $this->resourceClass = $resourceClass;
        $this->action = strtolower($action);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            $this->getChannel(),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'model' => ($this->resourceClass)::make($this->model)->resolve(),
            'action' => $this->action,
            'model_type' => $this->getModelType(),
        ];
    }

    public function broadcastAs(): string
    {
        return sprintf(
            '%s.%s',
            $this->getModelType(),
            $this->action
        );
    }

    protected function getChannel(): Channel
    {
        $channelName = sprintf(
            '%s.%s',
            $this->getModelType(),
            $this->model->getKey()
        );

        return match ($this->channel) {
            'presence' => new PresenceChannel($channelName),
            'private' => new Channel('private-' . $channelName),
            'public' => new Channel($channelName),
            default => throw new InvalidArgumentException('Invalid channel type'),
        };
    }

    protected function getModelType(): string
    {
        return str_replace('_', '.', $this->model->getTable());
    }

    /**
     * Validate that the resource class exists and extends JsonResource.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    protected function validateResourceClass(string $resourceClass): void
    {
        if (!class_exists($resourceClass) || !is_subclass_of($resourceClass, JsonResource::class)) {
            throw new InvalidArgumentException(
                sprintf('Resource class %s must exist and extend JsonResource', $resourceClass)
            );
        }
    }
}
