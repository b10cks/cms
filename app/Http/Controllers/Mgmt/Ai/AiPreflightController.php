<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiPreflightRequest;
use App\Models\Management\Space;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Changesets\PreflightEstimate;
use App\Services\Ai\ModelRegistry;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only pass before a bulk run: resolves the entries and estimates the
 * cost. The client starts the run with exactly these IDs.
 */
class AiPreflightController extends Controller
{
    public function __invoke(
        AiPreflightRequest $request,
        Space $space,
        EntryScope $scope,
        ChangeTargets $targets,
        AuthorizationService $auth,
        ModelRegistry $registry,
    ): JsonResponse {
        $type = (string) $request->input('scope.type');
        abort_unless($auth->canInSpace($request->user(), $space, $targets->for($type)->viewAbility()), 403);

        $filters = (array) $request->input('scope.filters', []);
        if ($request->has('scope.ids')) {
            $filters['ids'] = (array) $request->input('scope.ids');
        }

        $entries = $scope->resolve($type, $filters);
        $config = $request->filled('config_id') ? $space->aiConfigs()->find($request->input('config_id')) : $space->defaultAiConfig;
        $limit = (int) config('ai.inline_entry_limit');

        return response()->json(['data' => [
            'type' => $type,
            'count' => \count($entries),
            'ids' => array_column($entries, 'id'),
            'entries' => array_map(fn (array $entry) => ['id' => $entry['id'], 'name' => $entry['name']], \array_slice($entries, 0, 20)),
            'model' => $config ? "{$config->driver}:{$config->model}" : null,
            ...PreflightEstimate::for(
                $entries,
                $registry->findModelForConfig($config?->driver, $config?->model),
                PreflightEstimate::observedCostPerEntry($space->id, $request->input('skill')),
            ),
            'max_usd' => (float) config('ai.runs.max_usd'),
            'background' => \count($entries) > $limit,
            'inline_entry_limit' => $limit,
        ]]);
    }
}
