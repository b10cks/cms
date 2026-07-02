<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Space\UpdateSpaceAiSettingsRequest;
use App\Models\Management\Space;
use App\Services\Ai\ModelRegistry;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;

class SpaceAiSettingsController extends Controller
{
    public function __construct(
        protected ModelRegistry $registry
    ) {
    }

    public function update(UpdateSpaceAiSettingsRequest $request, Space $space): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.manage'), 403);

        $validated = $request->validated();

        $settings = $space->settings;

        if (isset($validated['model'])) {
            $model = $this->registry->findModel($validated['model']);
            if (!$model && $validated['model'] !== null) {
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
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.view'), 403);

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
