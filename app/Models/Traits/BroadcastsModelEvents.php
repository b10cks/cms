<?php

namespace App\Models\Traits;

use App\Events\ModelChangedEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;
use ReflectionClass;

trait BroadcastsModelEvents
{
    /**
     * Events that should trigger broadcasts.
     *
     * @var array<string>
     */
    protected array $broadcastEvents = [
        'created',
        'updated',
        'deleted',
    ];

    /**
     * The channel type to use for broadcasting.
     * Can be 'presence', 'private', or 'public'.
     */
    protected string $broadcastChannel = 'presence';

    /**
     * Boot the trait.
     */
    protected static function bootBroadcastsModelEvents(): void
    {
        static::resolveResourceClass();

        $events = (new static)->getBroadcastEvents();

        foreach ($events as $event) {
            static::{$event}(function (Model $model) use ($event) {
                $model->broadcastModelChange(
                    action: $event
                );
            });
        }
    }

    /**
     * Resolve the resource class from the model name if not set.
     */
    protected static function resolveResourceClass(): void
    {
        $instance = new static;

        if ($instance->broadcastResource !== null) {
            return;
        }

        $modelClass = (new ReflectionClass($instance))->getShortName();
        $resourceClass = "App\\Http\\Resources\\{$modelClass}Resource";

        if (!class_exists($resourceClass)) {
            throw new InvalidArgumentException(
                "Resource class {$resourceClass} does not exist. " .
                "Please create it or specify the \$broadcastResource property."
            );
        }

        if (!is_subclass_of($resourceClass, JsonResource::class)) {
            throw new InvalidArgumentException(
                "Resource class {$resourceClass} must extend JsonResource."
            );
        }

        $instance->broadcastResource = $resourceClass;
    }

    /**
     * Get the events that should trigger broadcasts.
     *
     * @return array<string>
     */
    protected function getBroadcastEvents(): array
    {
        return $this->broadcastEvents;
    }

    /**
     * Broadcast a model change event.
     */
    protected function broadcastModelChange(
        ?string $resourceClass = null,
        string  $action = 'changed',
        ?string $channel = null
    ): void
    {
        $resourceClass ??= $this->broadcastResource;
        $channel ??= $this->broadcastChannel;

        if (!$resourceClass) {
            throw new InvalidArgumentException(
                'No resource class specified for broadcasting'
            );
        }

        broadcast(new ModelChangedEvent(
            model: $this,
            resourceClass: $resourceClass,
            action: $action,
            channel: $channel
        ))->toOthers();
    }
}
