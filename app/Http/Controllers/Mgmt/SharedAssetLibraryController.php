<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SharedAssetLibraryResource;
use App\Models\Management\SharedAssetLibrary;
use App\Models\Management\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SharedAssetLibraryController extends Controller
{
    /**
     * Display a listing of shared asset libraries for a team
     */
    public function index(Request $request, Team $team): ResourceCollection
    {
        $libraries = SharedAssetLibrary::where('team_id', $team->id)
            ->with(['team', 'sharedAssets'])
            ->paginate($request->get('per_page', 20));

        return SharedAssetLibraryResource::collection($libraries);
    }

    /**
     * Store a newly created shared asset library
     */
    public function store(Request $request, Team $team): SharedAssetLibraryResource|JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|size:7',
            'is_default' => 'nullable|boolean',
            'settings' => 'nullable|array',
        ]);

        try {
            // Generate slug if not provided
            if (!isset($validated['slug'])) {
                $validated['slug'] = Str::slug($validated['name']);
            }

            // Ensure slug is unique for this team
            $existingSlug = SharedAssetLibrary::where('team_id', $team->id)
                ->where('slug', $validated['slug'])
                ->exists();

            if ($existingSlug) {
                return response()->json([
                    'message' => 'A library with this slug already exists for this team',
                    'errors' => ['slug' => ['The slug has already been taken.']]
                ], 422);
            }

            // If this is set as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                SharedAssetLibrary::where('team_id', $team->id)
                    ->update(['is_default' => false]);
            }

            $library = SharedAssetLibrary::create([
                'team_id' => $team->id,
                ...$validated,
            ]);

            return new SharedAssetLibraryResource($library->load(['team', 'sharedAssets']));
        } catch (\Exception $e) {
            Log::error('Failed to create shared asset library', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create shared asset library: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified shared asset library
     */
    public function show(Team $team, SharedAssetLibrary $library): SharedAssetLibraryResource|JsonResponse
    {
        // Ensure library belongs to team
        if ($library->team_id !== $team->id) {
            return response()->json([
                'message' => 'Library not found'
            ], 404);
        }

        return new SharedAssetLibraryResource($library->load(['team', 'sharedAssets', 'permissions']));
    }

    /**
     * Update the specified shared asset library
     */
    public function update(Request $request, Team $team, SharedAssetLibrary $library): SharedAssetLibraryResource|JsonResponse
    {
        // Ensure library belongs to team
        if ($library->team_id !== $team->id) {
            return response()->json([
                'message' => 'Library not found'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:50',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|size:7',
            'is_default' => 'nullable|boolean',
            'settings' => 'nullable|array',
        ]);

        try {
            // Check slug uniqueness if changed
            if (isset($validated['slug']) && $validated['slug'] !== $library->slug) {
                $existingSlug = SharedAssetLibrary::where('team_id', $team->id)
                    ->where('slug', $validated['slug'])
                    ->where('id', '!=', $library->id)
                    ->exists();

                if ($existingSlug) {
                    return response()->json([
                        'message' => 'A library with this slug already exists for this team',
                        'errors' => ['slug' => ['The slug has already been taken.']]
                    ], 422);
                }
            }

            // If this is set as default, unset other defaults
            if (($validated['is_default'] ?? false) && !$library->is_default) {
                SharedAssetLibrary::where('team_id', $team->id)
                    ->where('id', '!=', $library->id)
                    ->update(['is_default' => false]);
            }

            $library->update($validated);

            return new SharedAssetLibraryResource($library->load(['team', 'sharedAssets']));
        } catch (\Exception $e) {
            Log::error('Failed to update shared asset library', [
                'library_id' => $library->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update shared asset library: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified shared asset library
     */
    public function destroy(Team $team, SharedAssetLibrary $library): JsonResponse
    {
        // Ensure library belongs to team
        if ($library->team_id !== $team->id) {
            return response()->json([
                'message' => 'Library not found'
            ], 404);
        }

        try {
            // This will cascade delete all shared assets and permissions
            $library->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete shared asset library', [
                'library_id' => $library->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to delete shared asset library',
            ], 500);
        }
    }
}
