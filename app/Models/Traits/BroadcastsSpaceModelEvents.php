<?php

namespace App\Models\Traits;

use App\Events\Space\SpaceModelChanged;
use Illuminate\Database\Eloquent\Model;

trait BroadcastsSpaceModelEvents
{
    protected static function bootBroadcastsSpaceModelEvents(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::{$event}(function (Model $model) use ($event) {
                $space = request('space') ?? app('currentSpace');
                if (!$space) {
                    return;
                }
                broadcast(new SpaceModelChanged($model, $space, $model->spaceChannel, $event))->toOthers();
            });
        }
    }
}
