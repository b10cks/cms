<?php

namespace App\Events\Space;

use App\Events\Concerns\ResolvesBroadcastPayload;
use App\Models\Management\Space;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SpaceModelChanged implements ShouldBroadcast
{
    use ResolvesBroadcastPayload;
    use SerializesModels;

    /**
     * Reverb rejects messages above max_message_size (10KB by default);
     * leave headroom for the pusher envelope around the payload.
     */
    protected const MAX_DATA_BYTES = 8_500;

    private string $modelKey;

    private string $modelBaseClass;

    /** @var array<string, mixed> */
    private array $context;

    /** @var array<string, mixed>|null */
    private ?array $data;

    public function __construct(
        protected Space $space,
        protected string $resourceType,
        protected string $action,
        Model $model,
    ) {
        $this->modelKey = $model->getKey();
        $this->modelBaseClass = class_basename($model);
        $this->context = method_exists($model, 'broadcastContext') ? $model->broadcastContext() : [];
        $this->data = $this->action === 'deleted' ? null : $this->resolveData($model);
    }

    /**
     * Slim resource payload so listeners can patch their caches in place
     * instead of refetching. Resolved eagerly — the space connection is gone
     * once the event hits a queue worker. Null when no management resource
     * exists for the model, it fails to build, or it would push the message
     * over Reverb's size cap; the frontend then falls back to invalidation.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveData(Model $model): ?array
    {
        // `spaces.{space}.*` are public channels (no subscription auth) — a
        // model whose resource carries secrets opts out and stays id-only.
        if (method_exists($model, 'broadcastsResourceData') && ! $model->broadcastsResourceData()) {
            return null;
        }

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> $resourceClass */
        $resourceClass = 'App\\Http\\Resources\\Management\\'.$this->modelBaseClass.'Resource';

        if (! class_exists($resourceClass)) {
            return null;
        }

        try {
            $data = $this->resolveBroadcastPayload($resourceClass::make($model));

            return \strlen(json_encode($data, JSON_THROW_ON_ERROR)) > self::MAX_DATA_BYTES
                ? null
                : $data;
        } catch (\Throwable) {
            return null;
        }
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('spaces.'.$this->space->id.'.'.$this->resourceType),
        ];
    }

    public function broadcastAs(): string
    {
        return Str::snake($this->modelBaseClass).':'.$this->action;
    }

    public function broadcastWith(): array
    {
        $payload = [
            'id' => $this->modelKey,
            'action' => $this->action,
        ] + $this->context;

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }
}
