<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Migration\CreateMigration as CreateMigrationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Migration\CreateMigrationRequest;
use App\Http\Resources\Management\MigrationDetailResource;
use App\Http\Resources\Management\MigrationListResource;
use App\Models\Management\Space;
use App\Models\Management\SpaceMigration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class MigrationController extends Controller
{
    public function index(Request $request, Space $space): ResourceCollection
    {
        $this->authorize('viewAny', [SpaceMigration::class, $space]);

        $migrations = SpaceMigration::where(function ($q) use ($space) {
                $q->where('source_space_id', $space->id)
                  ->orWhere('target_space_id', $space->id);
            })
            ->with(['sourceSpace', 'targetSpace', 'creator'])
            ->latest()
            ->paginate();

        return MigrationListResource::collection($migrations);
    }

    public function store(CreateMigrationRequest $request, Space $space, CreateMigrationAction $action): MigrationDetailResource
    {
        $this->authorize('create', [SpaceMigration::class, $space]);

        $migration = $action->execute(
            $request->validated(),
            $space,
            auth()->user()
        );

        $migration->load(['sourceSpace', 'targetSpace', 'creator']);

        return new MigrationDetailResource($migration);
    }

    public function show(Space $space, SpaceMigration $migration): MigrationDetailResource
    {
        $this->authorize('view', $migration);

        $migration->load(['sourceSpace', 'targetSpace', 'creator']);

        return new MigrationDetailResource($migration);
    }

    public function destroy(Space $space, SpaceMigration $migration): JsonResponse
    {
        $this->authorize('delete', $migration);

        try {
            $migration->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete migration', [
                'migration_id' => $migration->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to delete migration'], 500);
        }
    }
}
