<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Space\UpdateSpaceAiSettingsRequest;
use App\Models\Management\Space;
use App\Services\Ai\ModelRegistry;
use Illuminate\Http\JsonResponse;

class SpaceAiSettingsController extends Controller
{
    public function __construct(
        protected ModelRegistry $registry
    ) {
    }

    public function update(UpdateSpaceAiSettingsRequest $request, Space $space): JsonResponse
    {
        $this->authorizeSpace($space, 'ai.manage');

        $validated = $request->validated();

        $settings = $space->settings;
        // Settings are magic properties: build the section, then assign it once.
        $ai = $settings->ai ?? [];

        if (\array_key_exists('model', $validated)) {
            $model = $validated['model'] === null ? null : $this->registry->findModel($validated['model']);
            if (!$model && $validated['model'] !== null) {
                return response()->json([
                    'message' => 'Invalid model ID',
                ], 422);
            }
            $ai['model'] = $validated['model'];
        }

        if (isset($validated['favourites'])) {
            $ai['favourites'] = $this->registry->validateFavourites($space, $validated['favourites']);
        }

        if (isset($validated['enabled'])) {
            $ai['enabled'] = $validated['enabled'];
        }

        if (isset($validated['asset_classification'])) {
            $ai['asset_classification'] = [
                ...($ai['asset_classification'] ?? []),
                ...$validated['asset_classification'],
            ];
        }

        $settings->ai = $ai;
        $space->settings = $settings;
        $space->save();

        return response()->json([
            'data' => [
                'ai' => $ai,
            ],
        ]);
    }

    public function show(Space $space): JsonResponse
    {
        $this->authorizeSpace($space, 'ai.view');

        // Spaces that never saved the AI section, or saved only part of it
        // (asset classification), still get every key; a missing `enabled` is on.
        return response()->json([
            'data' => [
                'ai' => [
                    'enabled' => true,
                    'model' => null,
                    'favourites' => [],
                    ...($space->settings->ai ?? []),
                ],
            ],
        ]);
    }
}
