<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\RedirectFilter;
use App\Http\Requests\Space\UpsertRedirectRequest;
use App\Http\Resources\Management\RedirectResource;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class RedirectController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [Redirect::class, $space]);

        $redirects = Redirect::filter(RedirectFilter::fromRequest($request))
            ->paginate($this->perPage($request));

        return RedirectResource::collection($redirects);
    }

    public function store(UpsertRedirectRequest $request, Space $space): RedirectResource
    {
        $this->authorize('create', [Redirect::class, $space]);

        $redirect = new Redirect();
        $redirect->fill($request->validated());

        if (!$redirect->save()) {
            Log::error('Failed to create redirect', ['space_id' => $space->id]);
            abort(500, 'Failed to create redirect');
        }

        return new RedirectResource($redirect);
    }

    public function show(Space $space, Redirect $redirect): RedirectResource
    {
        $this->authorize('view', [$redirect, $space]);

        return new RedirectResource($redirect);
    }

    public function update(UpsertRedirectRequest $request, Space $space, Redirect $redirect): RedirectResource
    {
        $this->authorize('update', [$redirect, $space]);

        $redirect->fill($request->validated());

        if (!$redirect->save()) {
            Log::error('Failed to update redirect', ['redirect_id' => $redirect->id, 'space_id' => $space->id]);
            abort(500, 'Failed to update redirect');
        }

        return new RedirectResource($redirect);
    }

    public function destroy(Space $space, Redirect $redirect): JsonResponse
    {
        $this->authorize('delete', [$redirect, $space]);

        try {
            $redirect->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete redirect', [
                'redirect_id' => $redirect->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the redirect',
            ], 500);
        }
    }
}
