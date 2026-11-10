<?php

namespace App\Services\Automation;

use App\Models\Management\Space;
use App\Support\SpaceContext;

class CurrentSpaceResolver
{
    /**
     * The space whose automations a model write triggers. Same rule as
     * SpaceModelResolver: only a bound route model or the ambient context,
     * never `request('space')`, which reads query and body input first and
     * would let `?space=x` send this space's records to another's automations.
     */
    public function resolve(): ?Space
    {
        return SpaceContext::resolve();
    }
}
