<?php

namespace App\Http\Controllers\Mgmt\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ProviderMetricsRequest;
use App\Services\Provider\ProviderMetricsService;
use Illuminate\Http\JsonResponse;

/** Root-only monthly instance metrics for the provider dashboard, cached for 15 minutes. */
class ProviderMetricsController extends Controller
{
    public function __invoke(ProviderMetricsRequest $request, ProviderMetricsService $metrics): JsonResponse
    {
        return response()->json(['data' => $metrics->cached($request->range())]);
    }
}
