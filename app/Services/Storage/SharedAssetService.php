<?php

namespace App\Services\Storage;

use App\Models\Management\SharedAsset;
use App\Models\Management\SharedAssetLibrary;
use App\Models\Management\SharedAssetPermission;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Space\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SharedAssetService
{
    /**
     * Share an asset from a space into a shared asset library
     */
    public function shareAsset(
        Asset $asset,
        SharedAssetLibrary $library,
        ?string $sharedName = null,
        ?string $sharedDescription = null,
        ?array $sharedTags = null,
        ?array $sharedMetadata = null
    ): SharedAsset {
        // Get the current space from the request
        $space = request('space');
        
        if (!$space) {
            throw new \Exception('No space context available');
        }

        // Check if already shared in this library
        $existing = SharedAsset::where('library_id', $library->id)
            ->where('source_space_id', $space->id)
            ->where('source_asset_id', $asset->id)
            ->first();

        if ($existing) {
            // Update existing shared asset
            $existing->update([
                'shared_name' => $sharedName,
                'shared_description' => $sharedDescription,
                'shared_tags' => $sharedTags,
                'shared_metadata' => $sharedMetadata,
            ]);
            
            return $existing;
        }

        // Create new shared asset
        return SharedAsset::create([
            'library_id' => $library->id,
            'source_space_id' => $space->id,
            'source_asset_id' => $asset->id,
            'shared_name' => $sharedName,
            'shared_description' => $sharedDescription,
            'shared_tags' => $sharedTags,
            'shared_metadata' => $sharedMetadata,
        ]);
    }

    /**
     * Unshare an asset from a shared asset library
     */
    public function unshareAsset(SharedAsset $sharedAsset): bool
    {
        try {
            $sharedAsset->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to unshare asset', [
                'shared_asset_id' => $sharedAsset->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get all shared assets accessible by a space
     * 
     * This includes:
     * 1. Assets shared with the space directly
     * 2. Assets shared with the space's team
     * 3. Assets inherited from parent teams
     */
    public function getSharedAssets(Space $space, ?SharedAssetLibrary $library = null): Collection
    {
        $cacheKey = "shared_assets_{$space->id}" . ($library ? "_{$library->id}" : '');
        
        return Cache::remember($cacheKey, 300, function () use ($space, $library) {
            // Get team hierarchy
            $team = $space->team;
            $teamIds = $this->getTeamHierarchy($team);
            
            // Find all libraries accessible to this space
            $libraryIds = $this->getAccessibleLibraries($space, $teamIds, $library);
            
            // Get shared assets from accessible libraries
            return SharedAsset::whereIn('library_id', $libraryIds)
                ->with(['library', 'sourceSpace'])
                ->get();
        });
    }

    /**
     * Get all libraries accessible to a team (including inherited from parents)
     */
    public function getInheritedLibraries(Team $team): Collection
    {
        $cacheKey = "inherited_libraries_{$team->id}";
        
        return Cache::remember($cacheKey, 300, function () use ($team) {
            $teamIds = $this->getTeamHierarchy($team);
            
            return SharedAssetLibrary::whereIn('team_id', $teamIds)
                ->with('team')
                ->get();
        });
    }

    /**
     * Check if a user/space/team can access a shared asset
     */
    public function canAccessSharedAsset(
        SharedAsset $sharedAsset,
        ?User $user = null,
        ?Space $space = null,
        ?Team $team = null,
        string $permission = SharedAssetPermission::PERMISSION_VIEW
    ): bool {
        $cacheKey = $this->getPermissionCacheKey($sharedAsset, $user, $space, $team, $permission);
        
        return Cache::remember($cacheKey, 300, function () use ($sharedAsset, $user, $space, $team, $permission) {
            // Check asset-level permissions first
            $hasAssetPermission = $this->checkAssetPermission($sharedAsset, $user, $space, $team, $permission);
            
            if ($hasAssetPermission) {
                return true;
            }
            
            // Check library-level permissions
            return $this->checkLibraryPermission($sharedAsset->library, $user, $space, $team, $permission);
        });
    }

    /**
     * Grant permission to access a library or shared asset
     */
    public function grantPermission(
        ?SharedAssetLibrary $library = null,
        ?SharedAsset $sharedAsset = null,
        string $accessorType,
        string $accessorId,
        string $permission = SharedAssetPermission::PERMISSION_VIEW,
        bool $inherited = false,
        ?array $conditions = null
    ): SharedAssetPermission {
        if (!$library && !$sharedAsset) {
            throw new \InvalidArgumentException('Either library or sharedAsset must be provided');
        }

        // Check if permission already exists
        $existing = SharedAssetPermission::where(function ($query) use ($library, $sharedAsset) {
            if ($library) {
                $query->where('library_id', $library->id)->whereNull('shared_asset_id');
            } else {
                $query->where('shared_asset_id', $sharedAsset->id);
            }
        })
            ->where('accessor_type', $accessorType)
            ->where('accessor_id', $accessorId)
            ->where('permission', $permission)
            ->first();

        if ($existing) {
            // Update existing permission
            $existing->update([
                'inherited' => $inherited,
                'conditions' => $conditions,
            ]);
            
            $this->clearPermissionCache();
            return $existing;
        }

        // Create new permission
        $permissionRecord = SharedAssetPermission::create([
            'library_id' => $library?->id,
            'shared_asset_id' => $sharedAsset?->id,
            'accessor_type' => $accessorType,
            'accessor_id' => $accessorId,
            'permission' => $permission,
            'inherited' => $inherited,
            'conditions' => $conditions,
        ]);

        $this->clearPermissionCache();
        return $permissionRecord;
    }

    /**
     * Revoke permission
     */
    public function revokePermission(SharedAssetPermission $permission): bool
    {
        try {
            $permission->delete();
            $this->clearPermissionCache();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to revoke permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get team hierarchy (team and all parent teams)
     */
    private function getTeamHierarchy(?Team $team): array
    {
        if (!$team) {
            return [];
        }

        $teamIds = [$team->id];
        $currentTeam = $team;

        // Traverse up the parent chain
        while ($currentTeam->parent_id) {
            $currentTeam = $currentTeam->parent;
            if ($currentTeam) {
                $teamIds[] = $currentTeam->id;
            } else {
                break;
            }
        }

        return $teamIds;
    }

    /**
     * Get all library IDs accessible to a space
     */
    private function getAccessibleLibraries(Space $space, array $teamIds, ?SharedAssetLibrary $specificLibrary = null): array
    {
        $query = SharedAssetPermission::query();

        if ($specificLibrary) {
            $query->where('library_id', $specificLibrary->id);
        }

        // Check for space-level permissions
        $spacePermissions = $query->where('accessor_type', Space::class)
            ->where('accessor_id', $space->id)
            ->pluck('library_id')
            ->toArray();

        // Check for team-level permissions
        $teamPermissions = SharedAssetPermission::whereIn('accessor_type', [Team::class])
            ->whereIn('accessor_id', $teamIds)
            ->pluck('library_id')
            ->toArray();

        // Merge and deduplicate
        $libraryIds = array_unique(array_merge($spacePermissions, $teamPermissions));

        return array_filter($libraryIds); // Remove nulls
    }

    /**
     * Check asset-level permission
     */
    private function checkAssetPermission(
        SharedAsset $sharedAsset,
        ?User $user,
        ?Space $space,
        ?Team $team,
        string $permission
    ): bool {
        $query = SharedAssetPermission::where('shared_asset_id', $sharedAsset->id)
            ->where('permission', $permission);

        // Check user permission
        if ($user) {
            $hasUserPermission = $query->clone()
                ->where('accessor_type', User::class)
                ->where('accessor_id', $user->id)
                ->exists();
            
            if ($hasUserPermission) {
                return true;
            }
        }

        // Check space permission
        if ($space) {
            $hasSpacePermission = $query->clone()
                ->where('accessor_type', Space::class)
                ->where('accessor_id', $space->id)
                ->exists();
            
            if ($hasSpacePermission) {
                return true;
            }
        }

        // Check team permission (including hierarchy)
        if ($team) {
            $teamIds = $this->getTeamHierarchy($team);
            
            $hasTeamPermission = $query->clone()
                ->where('accessor_type', Team::class)
                ->whereIn('accessor_id', $teamIds)
                ->exists();
            
            if ($hasTeamPermission) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check library-level permission
     */
    private function checkLibraryPermission(
        SharedAssetLibrary $library,
        ?User $user,
        ?Space $space,
        ?Team $team,
        string $permission
    ): bool {
        $query = SharedAssetPermission::where('library_id', $library->id)
            ->whereNull('shared_asset_id')
            ->where('permission', $permission);

        // Check user permission
        if ($user) {
            $hasUserPermission = $query->clone()
                ->where('accessor_type', User::class)
                ->where('accessor_id', $user->id)
                ->exists();
            
            if ($hasUserPermission) {
                return true;
            }
        }

        // Check space permission
        if ($space) {
            $hasSpacePermission = $query->clone()
                ->where('accessor_type', Space::class)
                ->where('accessor_id', $space->id)
                ->exists();
            
            if ($hasSpacePermission) {
                return true;
            }
        }

        // Check team permission (including hierarchy)
        if ($team) {
            $teamIds = $this->getTeamHierarchy($team);
            
            $hasTeamPermission = $query->clone()
                ->where('accessor_type', Team::class)
                ->whereIn('accessor_id', $teamIds)
                ->exists();
            
            if ($hasTeamPermission) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get cache key for permission check
     */
    private function getPermissionCacheKey(
        SharedAsset $sharedAsset,
        ?User $user,
        ?Space $space,
        ?Team $team,
        string $permission
    ): string {
        $parts = ['perm', $sharedAsset->id, $permission];
        
        if ($user) {
            $parts[] = 'u_' . $user->id;
        }
        if ($space) {
            $parts[] = 's_' . $space->id;
        }
        if ($team) {
            $parts[] = 't_' . $team->id;
        }
        
        return implode('_', $parts);
    }

    /**
     * Clear permission cache
     */
    private function clearPermissionCache(): void
    {
        // In production, you might want to use cache tags for more efficient clearing
        Cache::flush();
    }
}
