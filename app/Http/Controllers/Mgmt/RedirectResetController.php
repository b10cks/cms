<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\RedirectResource;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\Audit\AuditActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RedirectResetController extends Controller
{
    public function __invoke(Request $request, Space $space, Redirect $redirect): RedirectResource|JsonResponse
    {
        $this->authorize('update', [$redirect, $space]);

        try {
            $redirect->withoutAudit();
            $redirect->hits = 0;
            $redirect->last_used_at = null;
            $redirect->save();
            $redirect->auditSpaceEvent('reset', AuditActor::user($request->user()));

            return new RedirectResource($redirect);
        } catch (\Exception $e) {
            Log::error('Failed to reset redirect counter', [
                'redirect_id' => $redirect->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while resetting the redirect counter',
            ], 500);
        }
    }
}
