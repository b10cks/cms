<?php

namespace App\Events\Space;

use App\Events\Concerns\ResolvesBroadcastPayload;
use App\Models\Management\Space;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SpaceModelChanged implements ShouldBroadcast
{
    use InteractsWithSockets;
    use ResolvesBroadcastPayload;
    use SerializesModels;

    /**
     * Room left in Reverb's `max_request_size` for what the body measurement
     * doesn't see: the request line with its auth query string, the headers
     * and the `socket_id` of toOthers().
     */
    protected const REQUEST_OVERHEAD_BYTES = 1_000;

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

        if ($this->data !== null && $this->requestBodyBytes() > $this->maxRequestBodyBytes()) {
            $this->data = null;
        }
    }

    /**
     * Slim resource payload so listeners can patch their caches in place
     * instead of refetching. Resolved eagerly — the space connection is gone
     * once the event hits a queue worker. Null when no management resource
     * exists for the model or it fails to build. The constructor also drops it
     * when it would push the request over Reverb's size cap. Either way the
     * frontend falls back to invalidation.
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
            return $this->resolveBroadcastPayload($resourceClass::make($model));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Size of the body the Pusher client POSTs to Reverb. The payload is JSON
     * encoded, then encoded again as a string inside the body, which escapes
     * every quote and turns non-ASCII into `\uXXXX`. Reverb answers an
     * oversized request with 413, the broadcast job fails, and the event
     * reaches nobody, not even as an id-only invalidation.
     */
    private function requestBodyBytes(): int
    {
        return \strlen(json_encode([
            'name' => $this->broadcastAs(),
            'data' => json_encode($this->broadcastWith(), JSON_THROW_ON_ERROR),
            'channels' => array_map(fn (PrivateChannel $channel): string => $channel->name, $this->broadcastOn()),
        ], JSON_THROW_ON_ERROR));
    }

    private function maxRequestBodyBytes(): int
    {
        return (int) config('reverb.servers.reverb.max_request_size', 10_000) - self::REQUEST_OVERHEAD_BYTES;
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
