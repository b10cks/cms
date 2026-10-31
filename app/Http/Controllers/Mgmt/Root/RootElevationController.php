<?php

namespace App\Http\Controllers\Mgmt\Root;

use App\Http\Controllers\Controller;
use App\Http\Requests\Root\StartElevationRequest;
use App\Services\Auth\RootElevationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RootElevationController extends Controller
{
    public function show(Request $request, RootElevationService $elevations): JsonResponse
    {
        abort_unless((bool) $request->user()->is_root, 403);

        return response()->json(['data' => $elevations->state($request->user())]);
    }

    public function store(StartElevationRequest $request, RootElevationService $elevations): JsonResponse
    {
        $elevations->start(
            $request->user(),
            $request->string('reason')->trim()->toString(),
            $request->minutes(),
        );

        return response()->json(['data' => $elevations->state($request->user())], 201);
    }

    public function destroy(Request $request, RootElevationService $elevations): JsonResponse
    {
        abort_unless((bool) $request->user()->is_root, 403);

        $elevations->end($request->user());

        return response()->json(['data' => $elevations->state($request->user())]);
    }
}
