<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PlanResource;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SpacePlansController extends Controller
{
    /**
     * List the plans available to a space: all active public plans plus any
     * custom (subsidized/agency) plans granted to this space.
     */
    public function __invoke(Space $space): ResourceCollection
    {
        $this->authorize('viewBilling', $space);

        $plans = Plan::active()
            ->where(fn ($query) => $query
                ->where('is_public', true)
                ->orWhereHas('spaces', fn ($q) => $q->whereKey($space->id)))
            ->get();

        return PlanResource::collection($plans);
    }
}
