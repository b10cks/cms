<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RedirectLookupRequest;
use App\Models\Space\Redirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RedirectLookupController extends Controller
{
    public function __invoke(RedirectLookupRequest $request): JsonResponse
    {
        $source = $request->validated('source');

        $redirect = Redirect::where('source', $source)->first();

        if (!$redirect) {
            return response()->json(false);
        }

        try {
            $redirect->trackUsage();
        } catch (\Exception $e) {
            Log::error('Failed to track redirect usage', [
                'redirect_id' => $redirect->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
        } finally {
            return response()->json([
                'target' => $redirect->target,
                'status_code' => $redirect->status_code,
            ]);
        }
    }
}
