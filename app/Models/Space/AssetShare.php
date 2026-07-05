<?php

namespace App\Models\Space;

use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A public, token-addressed share link exposing a set of assets.
 *
 * Space-database model: the public URL carries the space id
 * (/share/{space}/{token}), so the share can be resolved without any global
 * lookup table.
 *
 * @property string $id
 * @property string $token
 * @property string $name
 * @property string|null $description
 * @property string $source_type 'collection'|'selection'|'folder'
 * @property string|null $collection_id
 * @property string|null $folder_id
 * @property array<int, string>|null $asset_ids
 * @property string|null $package_id
 * @property string|null $password Hashed
 * @property Carbon|null $expires_at
 * @property int|null $download_limit
 * @property int $download_count
 * @property int $view_count
 * @property bool $allow_individual_downloads
 * @property array<array-key, mixed>|null $settings
 * @property string|null $created_by_id Management-database users id
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AssetPackage|null $package
 * @property-read Collection<int, AssetShareEvent> $events
 * @property-read User|null $creator
 *
 * @mixin \Eloquent
 */
class AssetShare extends SpaceModel
{
    use Filterable;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'asset_shares';

    protected $fillable = [
        'token',
        'name',
        'description',
        'source_type',
        'collection_id',
        'folder_id',
        'asset_ids',
        'package_id',
        'password',
        'expires_at',
        'download_limit',
        'download_count',
        'view_count',
        'allow_individual_downloads',
        'settings',
        'created_by_id',
        'last_accessed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'asset_ids' => 'array',
        'settings' => 'array',
        'download_limit' => 'integer',
        'download_count' => 'integer',
        'view_count' => 'integer',
        'allow_individual_downloads' => 'boolean',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revoked_at' => 'datetime',
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
     * Random 48-char token (column allows 64 ascii chars).
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(AssetPackage::class, 'package_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetShareEvent::class, 'share_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id', 'id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The package download limit has been reached.
     */
    public function isExhausted(): bool
    {
        return $this->download_limit !== null
            && $this->download_count >= $this->download_limit;
    }

    public function hasPassword(): bool
    {
        return ! empty($this->password);
    }

    /**
     * Whether the share may be served publicly at all (password/limit gates
     * are enforced separately).
     */
    public function isAccessible(): bool
    {
        return ! $this->trashed()
            && ! $this->isRevoked()
            && ! $this->isExpired();
    }
}
