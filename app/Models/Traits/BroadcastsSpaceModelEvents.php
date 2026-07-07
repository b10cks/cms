<?php

namespace App\Models\Traits;

use App\Events\Space\SpaceModelChanged;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Model;

trait BroadcastsSpaceModelEvents
{
    protected static function bootBroadcastsSpaceModelEvents(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::{$event}(function (Model $model) use ($event) {
                $space = request('space') ?? SpaceContext::current();
                if (!$space) {
                    return;
                }
                broadcast(new SpaceModelChanged($space, $model->spaceChannel, $event, $model))->toOthers();
            });
        }
    }
}
