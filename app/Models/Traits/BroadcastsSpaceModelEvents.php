<?php

namespace App\Models\Traits;

use App\Events\Space\SpaceModelChanged;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Model;

trait BroadcastsSpaceModelEvents
{
    /**
     * Per using class: true while a bulk operation runs with broadcasts muted.
     * Model::withoutEvents() is not an option here — it would also disable
     * ULID generation, audit hooks and every other model event.
     */
    protected static bool $broadcastsMuted = false;

    /**
     * Run a bulk operation without a broadcast per model event. The caller is
     * responsible for emitting one summarizing event afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutBroadcasts(callable $callback): mixed
    {
        static::$broadcastsMuted = true;

        try {
            return $callback();
        } finally {
            static::$broadcastsMuted = false;
        }
    }

    protected static function bootBroadcastsSpaceModelEvents(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::{$event}(function (Model $model) use ($event) {
                if (static::$broadcastsMuted) {
                    return;
                }

                $space = request('space') ?? SpaceContext::current();
                if (!$space) {
                    return;
                }
                broadcast(new SpaceModelChanged($space, $model->spaceChannel, $event, $model))->toOthers();
            });
        }
    }
}
