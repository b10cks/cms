<?php

namespace App\Models\Management;

use App\Models\Traits\HasPurifiedAttributes;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared Asset Library Model
 * 
 * Represents a team-level library of shared assets that can be accessed
 * by multiple spaces within the team and its child teams.
 *
 * @property string $id
 * @property string $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $color
 * @property bool $is_default
 * @property array|null $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SharedAsset> $sharedAssets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SharedAssetPermission> $permissions
 */
class SharedAssetLibrary extends GlobalModel
{
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'shared_asset_libraries';

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'is_default',
        'settings',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'settings' => 'array',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    /**
     * Get the team that owns this library
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    /**
     * Get all shared assets in this library
     */
    public function sharedAssets(): HasMany
    {
        return $this->hasMany(SharedAsset::class, 'library_id', 'id');
    }

    /**
     * Get permissions for this library
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(SharedAssetPermission::class, 'library_id', 'id')
            ->whereNull('shared_asset_id');
    }

    /**
     * Check if a team/space has access to this library
     */
    public function hasAccess(string $accessorType, string $accessorId): bool
    {
        return $this->permissions()
            ->where('accessor_type', $accessorType)
            ->where('accessor_id', $accessorId)
            ->exists();
    }

    /**
     * Get all teams/spaces that have access to this library
     */
    public function getAccessors(): \Illuminate\Support\Collection
    {
        return $this->permissions()
            ->get()
            ->map(function ($permission) {
                return [
                    'type' => $permission->accessor_type,
                    'id' => $permission->accessor_id,
                    'permission' => $permission->permission,
                    'inherited' => $permission->inherited,
                ];
            });
    }
}
