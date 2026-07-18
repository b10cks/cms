<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Resources\Management\PlanResource;
use App\Models\Management\Plan;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PlanController
{
    /**
     * List all active public plans.
     * Public endpoint, cached for 1 hour. Custom (non-public) plans are only
     * exposed via the space-scoped listing.
     */
    public function __invoke(): ResourceCollection
    {
        $plans = Plan::active()->public()->get();

        return PlanResource::collection($plans);
    }
}
