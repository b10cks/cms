<?php

namespace App\Models\Management;

use App\Models\Space\Asset;
use App\Models\Traits\HasPurifiedAttributes;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared Asset Model
 * 
 * Represents a reference to an asset that has been shared from a space
 * into a shared asset library. This is a pointer model that references
 * the actual asset in the space's database.
 *
 * @property string $id
 * @property string $library_id
 * @property string $source_space_id
 * @property string $source_asset_id
 * @property string|null $shared_name
 * @property string|null $shared_description
 * @property array|null $shared_tags
 * @property array|null $shared_metadata
 * @property int $access_count
 * @property \Illuminate\Support\Carbon|null $last_accessed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read SharedAssetLibrary $library
 * @property-read Space $sourceSpace
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SharedAssetPermission> $permissions
 */
class SharedAsset extends GlobalModel
{
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'shared_assets';

    protected $fillable = [
        'library_id',
        'source_space_id',
        'source_asset_id',
        'shared_name',
        'shared_description',
        'shared_tags',
        'shared_metadata',
        'access_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'shared_tags' => 'array',
        'shared_metadata' => 'array',
        'access_count' => 'integer',
        'last_accessed_at' => 'datetime',
    ];

    protected function sharedName(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function sharedDescription(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    /**
     * Get the library this asset belongs to
     */
    public function library(): BelongsTo
    {
        return $this->belongsTo(SharedAssetLibrary::class, 'library_id', 'id');
    }

    /**
     * Get the source space where the original asset is stored
     */
    public function sourceSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'source_space_id', 'id');
    }

    /**
     * Get permissions for this specific shared asset
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(SharedAssetPermission::class, 'shared_asset_id', 'id');
    }

    /**
     * Retrieve the actual asset from the source space's database
     * 
     * This requires switching database connections to access the space's database
     */
    public function getSourceAsset(): ?Asset
    {
        try {
            // Store current request space
            $currentSpace = request('space');
            
            // Temporarily set the source space as the current space
            // This allows the SpaceModelResolver to connect to the correct database
            request()->merge(['space' => $this->sourceSpace]);
            
            // Fetch the asset from the source space's database
            $asset = Asset::find($this->source_asset_id);
            
            // Restore original space
            if ($currentSpace) {
                request()->merge(['space' => $currentSpace]);
            }
            
            return $asset;
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve source asset', [
                'shared_asset_id' => $this->id,
                'source_space_id' => $this->source_space_id,
                'source_asset_id' => $this->source_asset_id,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Increment the access count for this shared asset
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->last_accessed_at = now();
        $this->save();
    }

    /**
     * Check if an accessor has permission to access this asset
     */
    public function hasAccess(string $accessorType, string $accessorId, string $permission = 'view'): bool
    {
        // Check asset-level permissions
        $hasAssetPermission = $this->permissions()
            ->where('accessor_type', $accessorType)
            ->where('accessor_id', $accessorId)
            ->where('permission', $permission)
            ->exists();
        
        if ($hasAssetPermission) {
            return true;
        }

        // Check library-level permissions
        return $this->library->hasAccess($accessorType, $accessorId);
    }

    /**
     * Get the display name (prefer shared name, fallback to original)
     */
    public function getDisplayName(): ?string
    {
        if ($this->shared_name) {
            return $this->shared_name;
        }

        $sourceAsset = $this->getSourceAsset();
        return $sourceAsset?->filename;
    }

    /**
     * Get combined metadata (merge shared and original)
     */
    public function getCombinedMetadata(): array
    {
        $sourceAsset = $this->getSourceAsset();
        $originalMetadata = $sourceAsset?->metadata ?? [];
        $sharedMetadata = $this->shared_metadata ?? [];

        return array_merge($originalMetadata, $sharedMetadata);
    }
}
