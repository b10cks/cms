<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Shared Asset Permission Model
 * 
 * Controls access to shared asset libraries and individual shared assets.
 * Supports library-level and asset-level permissions with polymorphic accessors
 * (Team, Space, or User).
 *
 * @property string $id
 * @property string|null $library_id
 * @property string|null $shared_asset_id
 * @property string $accessor_type
 * @property string $accessor_id
 * @property string $permission
 * @property bool $inherited
 * @property array|null $conditions
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read SharedAssetLibrary|null $library
 * @property-read SharedAsset|null $sharedAsset
 * @property-read \Illuminate\Database\Eloquent\Model $accessor
 */
class SharedAssetPermission extends GlobalModel
{
    use Filterable;
    use HasFactory;
    use HasUlids;

    protected $table = 'shared_asset_permissions';

    /**
     * Permission types
     */
    public const PERMISSION_VIEW = 'view';
    public const PERMISSION_USE = 'use';
    public const PERMISSION_DOWNLOAD = 'download';

    protected $fillable = [
        'library_id',
        'shared_asset_id',
        'accessor_type',
        'accessor_id',
        'permission',
        'inherited',
        'conditions',
    ];

    protected $casts = [
        'inherited' => 'boolean',
        'conditions' => 'array',
    ];

    /**
     * Get the library this permission applies to (if library-level)
     */
    public function library(): BelongsTo
    {
        return $this->belongsTo(SharedAssetLibrary::class, 'library_id', 'id');
    }

    /**
     * Get the shared asset this permission applies to (if asset-level)
     */
    public function sharedAsset(): BelongsTo
    {
        return $this->belongsTo(SharedAsset::class, 'shared_asset_id', 'id');
    }

    /**
     * Get the accessor (Team, Space, or User) that has this permission
     */
    public function accessor(): MorphTo
    {
        return $this->morphTo('accessor', 'accessor_type', 'accessor_id');
    }

    /**
     * Check if this is a library-level permission
     */
    public function isLibraryPermission(): bool
    {
        return !is_null($this->library_id) && is_null($this->shared_asset_id);
    }

    /**
     * Check if this is an asset-level permission
     */
    public function isAssetPermission(): bool
    {
        return !is_null($this->shared_asset_id);
    }

    /**
     * Check if the permission matches the given criteria
     */
    public function matches(string $accessorType, string $accessorId, ?string $permission = null): bool
    {
        $matches = $this->accessor_type === $accessorType && $this->accessor_id === $accessorId;

        if ($permission) {
            $matches = $matches && $this->permission === $permission;
        }

        return $matches;
    }

    /**
     * Validate if conditions are met (if any)
     * 
     * @param array $context Additional context for condition evaluation
     * @return bool
     */
    public function conditionsMet(array $context = []): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        // Example conditions that could be implemented:
        // - Time-based access (only during business hours)
        // - IP-based access (only from specific IPs)
        // - Usage limits (max downloads per day)
        // - Environment-based (only in production)

        foreach ($this->conditions as $key => $value) {
            switch ($key) {
                case 'expires_at':
                    if (now()->isAfter($value)) {
                        return false;
                    }
                    break;
                    
                case 'starts_at':
                    if (now()->isBefore($value)) {
                        return false;
                    }
                    break;
                    
                case 'max_downloads':
                    // Would need to track download count
                    // For now, assume condition is met
                    break;
                    
                default:
                    // Unknown condition, assume met
                    break;
            }
        }

        return true;
    }

    /**
     * Scope to filter by accessor
     */
    public function scopeForAccessor($query, string $accessorType, string $accessorId)
    {
        return $query->where('accessor_type', $accessorType)
            ->where('accessor_id', $accessorId);
    }

    /**
     * Scope to filter by permission type
     */
    public function scopeWithPermission($query, string $permission)
    {
        return $query->where('permission', $permission);
    }

    /**
     * Scope to filter by library
     */
    public function scopeForLibrary($query, string $libraryId)
    {
        return $query->where('library_id', $libraryId)
            ->whereNull('shared_asset_id');
    }

    /**
     * Scope to filter by shared asset
     */
    public function scopeForSharedAsset($query, string $sharedAssetId)
    {
        return $query->where('shared_asset_id', $sharedAssetId);
    }

    /**
     * Get all available permission types
     */
    public static function getPermissionTypes(): array
    {
        return [
            self::PERMISSION_VIEW => 'View asset details',
            self::PERMISSION_USE => 'Use asset in content',
            self::PERMISSION_DOWNLOAD => 'Download asset file',
        ];
    }
}
