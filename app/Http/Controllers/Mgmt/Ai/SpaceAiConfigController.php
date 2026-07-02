<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SpaceAiConfigCollection;
use App\Http\Resources\Management\SpaceAiConfigResource;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Services\Ai\ModelRegistry;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpaceAiConfigController extends Controller
{
    public function __construct(
        protected ModelRegistry $registry
    ) {}

    public function index(Space $space): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.view'), 403);

        $configs = $space->aiConfigs()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json(
            new SpaceAiConfigCollection($configs)
        );
    }

    public function store(Request $request, Space $space): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:10000'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['sometimes', 'integer', 'min:256', 'max:200000'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! $this->registry->getDriver($validated['driver'])) {
            return response()->json([
                'message' => 'Invalid driver',
            ], 422);
        }

        $modelFullId = $validated['driver'] . ':' . $validated['model'];
        if (! $this->registry->findModel($modelFullId)) {
            return response()->json([
                'message' => 'Invalid model',
            ], 422);
        }

        $hasExistingConfigs = $space->aiConfigs()->exists();

        if ($validated['is_default'] ?? false) {
            $space->aiConfigs()->update(['is_default' => false]);
        } elseif (! $hasExistingConfigs) {
            $validated['is_default'] = true;
        }

        $config = $space->aiConfigs()->create($validated);

        return response()->json([
            'data' => new SpaceAiConfigResource($config),
        ], 201);
    }

    public function show(Space $space, SpaceAiConfig $aiConfig): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.view'), 403);

        if ($aiConfig->space_id !== $space->id) {
            abort(404);
        }

        return response()->json([
            'data' => new SpaceAiConfigResource($aiConfig),
        ]);
    }

    public function update(Request $request, Space $space, SpaceAiConfig $aiConfig): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.manage'), 403);

        if ($aiConfig->space_id !== $space->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'driver' => ['sometimes', 'string', 'max:50'],
            'model' => ['sometimes', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:10000'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['sometimes', 'integer', 'min:256', 'max:200000'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['driver']) && ! $this->registry->getDriver($validated['driver'])) {
            return response()->json([
                'message' => 'Invalid driver',
            ], 422);
        }

        if (isset($validated['driver']) && isset($validated['model'])) {
            $modelFullId = $validated['driver'] . ':' . $validated['model'];
            if (! $this->registry->findModel($modelFullId)) {
                return response()->json([
                    'message' => 'Invalid model',
                ], 422);
            }
        }

        if (($validated['is_default'] ?? false) && ! $aiConfig->is_default) {
            $space->aiConfigs()->where('id', '!=', $aiConfig->id)->update(['is_default' => false]);
        }

        $aiConfig->update($validated);

        return response()->json([
            'data' => new SpaceAiConfigResource($aiConfig),
        ]);
    }

    public function destroy(Space $space, SpaceAiConfig $aiConfig): Response
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'ai.manage'), 403);

        if ($aiConfig->space_id !== $space->id) {
            abort(404);
        }

        $aiConfig->delete();

        return response()->noContent();
    }
}
