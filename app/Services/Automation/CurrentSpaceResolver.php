<?php

namespace App\Services\Automation;

use App\Models\Management\Space;

class CurrentSpaceResolver
{
    public function resolve(): ?Space
    {
        $space = request()?->route('space') ?? request('space');

        if ($space instanceof Space) {
            return $space;
        }

        if (is_string($space) && $space !== '') {
            return Space::query()->find($space);
        }

        if (app()->bound('currentSpace')) {
            $currentSpace = app()->get('currentSpace');

            return $currentSpace instanceof Space ? $currentSpace : null;
        }

        return null;
    }
}
