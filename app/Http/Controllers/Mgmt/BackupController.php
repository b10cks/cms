<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Backup\CreateBackup as CreateBackupAction;
use App\Actions\Backup\DeleteBackup as DeleteBackupAction;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BackupFilter;
use App\Http\Requests\Backup\CreateBackupRequest;
use App\Http\Requests\Backup\UpdateBackupRequest;
use App\Http\Resources\Management\BackupDetailResource;
use App\Http\Resources\Management\BackupListResource;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    public function index(Request $request, Space $space): ResourceCollection
    {
        $this->authorize('viewAny', [SpaceBackup::class, $space]);
        
        $backups = SpaceBackup::filter(BackupFilter::fromRequest($request))
            ->where('space_id', $space->id)
            ->with('creator')
            ->paginate();

        return BackupListResource::collection($backups);
    }

    public function store(CreateBackupRequest $request, Space $space, CreateBackupAction $action): BackupDetailResource
    {
        $this->authorize('create', [SpaceBackup::class, $space]);
        
        $backup = $action->execute(
            $request->validated(),
            $space,
            auth()->user()
        );

        return new BackupDetailResource($backup);
    }

    public function show(Space $space, SpaceBackup $backup): BackupDetailResource
    {
        $this->authorize('view', $backup);
        
        $backup->load('creator');
        
        return new BackupDetailResource($backup);
    }

    public function update(UpdateBackupRequest $request, Space $space, SpaceBackup $backup): BackupDetailResource
    {
        $this->authorize('update', $backup);
        
        $backup->fill($request->validated());
        
        if (!$backup->save()) {
            Log::error('Failed to update backup', ['backup_id' => $backup->id]);
            abort(500, 'Failed to update backup');
        }

        $backup->load('creator');
        return new BackupDetailResource($backup);
    }

    public function destroy(Space $space, SpaceBackup $backup, DeleteBackupAction $action): JsonResponse
    {
        $this->authorize('delete', $backup);
        
        try {
            $action->execute($backup);
            
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete backup', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the backup',
            ], 500);
        }
    }
}
