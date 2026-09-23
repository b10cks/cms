<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Space\Asset;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AssetClassificationService;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Queue AI classification for a selection of assets or the whole library.
 * Results land asynchronously; the asset broadcasts patch open grids live.
 */
class AssetClassificationController extends Controller
{
    use SpaceFromQuery;

    public function __invoke(Request $request, AssetClassificationService $service): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['selection', 'all'])],
            'asset_ids' => ['required_if:scope,selection', 'array', 'max:2000'],
            'asset_ids.*' => ['string'],
            'languages' => ['sometimes', 'array', 'min:1'],
            'languages.*' => ['string'],
            'config_id' => ['sometimes', 'nullable', 'string'],
            'overwrite' => ['sometimes', 'boolean'],
        ]);

        $space = $this->getSpaceFromQuery();
        $this->authorizeSpaceAbility($space, 'assets.manage');

        if (! $service->isEnabled($space)) {
            return response()->json(['message' => 'AI features are disabled for this space.'], 422);
        }

        $languages = isset($validated['languages'])
            ? array_values(array_unique($validated['languages']))
            : null;

        if ($languages !== null && ! $service->validateLanguages($space, $languages)) {
            return response()->json(['message' => 'Invalid classification languages requested.'], 422);
        }

        // Fails with a precise 422 before anything is queued; AiServiceException
        // renders itself.
        $config = $service->resolveVisionConfig($space, $validated['config_id'] ?? null);

        $restore = SpaceContext::enter($space);

        try {
            $query = Asset::query()->with('folder');

            if ($validated['scope'] === 'selection') {
                $query->whereIn('id', $validated['asset_ids']);
            }

            $result = $service->queue(
                $space,
                $query,
                $languages,
                $config->id,
                (bool) ($validated['overwrite'] ?? false),
            );
        } finally {
            $restore();
        }

        return response()->json(['data' => $result]);
    }
}
