<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Resources\Management\PlanResource;
use App\Models\Management\Plan;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PlanController
{
    /**
     * List all active plans.
     * Public endpoint, cached for 1 hour.
     */
    public function __invoke(): ResourceCollection
    {
        $plans = Plan::active()->get();

        return PlanResource::collection($plans);
    }
}
