<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Services\Ai\ModelRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpaceAiSettingsController extends Controller
{
    public function __construct(
        protected ModelRegistry $registry
    ) {}

    public function update(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['sometimes', 'nullable', 'string'],
            'favourites' => ['sometimes', 'array'],
            'favourites.*' => ['string'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = $space->settings;

        if (isset($validated['model'])) {
            $model = $this->registry->findModel($validated['model']);
            if (! $model && $validated['model'] !== null) {
                return response()->json([
                    'message' => 'Invalid model ID',
                ], 422);
            }
            $settings->ai['model'] = $validated['model'];
        }

        if (isset($validated['favourites'])) {
            $settings->ai['favourites'] = $this->registry->validateFavourites(
                $space,
                $validated['favourites']
            );
        }

        if (isset($validated['enabled'])) {
            $settings->ai['enabled'] = $validated['enabled'];
        }

        $space->settings = $settings;
        $space->save();

        return response()->json([
            'data' => [
                'ai' => $settings->ai,
            ],
        ]);
    }

    public function show(Space $space): JsonResponse
    {
        return response()->json([
            'data' => [
                'ai' => $space->settings->ai ?? [
                    'enabled' => true,
                    'model' => null,
                    'favourites' => [],
                ],
            ],
        ]);
    }
}
