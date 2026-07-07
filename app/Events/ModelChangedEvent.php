<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

class ModelChangedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    protected string $action;

    protected string $modelKey;

    protected string $modelType;

    /**
     * Resolved eagerly: the model may live on a per-space connection that is
     * not bound on a queue worker, and deleted models can't be restored at all.
     *
     * @var array<string, mixed>
     */
    protected array $payload;

    /**
     * Create a new event instance.
     *
     * @param Model $model The model that was changed
     * @param class-string<JsonResource> $resourceClass The resource class to use for transformation
     * @param string $action The action that occurred (created, updated, deleted, etc.)
     * @param string $channel The channel type (presence, private, public)
     */
    public function __construct(
        Model            $model,
        string           $resourceClass,
        string           $action = 'changed',
        protected string $channel = 'presence'
    )
    {
        $this->validateResourceClass($resourceClass);
        $this->action = strtolower($action);
        $this->modelKey = (string) $model->getKey();
        $this->modelType = str_replace('_', '.', $model->getTable());
        $this->payload = $resourceClass::make($model)->resolve();
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
            'model' => $this->payload,
            'action' => $this->action,
            'model_type' => $this->modelType,
        ];
    }

    public function broadcastAs(): string
    {
        return sprintf(
            '%s.%s',
            $this->modelType,
            $this->action
        );
    }

    protected function getChannel(): Channel
    {
        $channelName = sprintf(
            '%s.%s',
            $this->modelType,
            $this->modelKey
        );

        return match ($this->channel) {
            'presence' => new PresenceChannel($channelName),
            'private' => new Channel('private-' . $channelName),
            'public' => new Channel($channelName),
            default => throw new InvalidArgumentException('Invalid channel type'),
        };
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
