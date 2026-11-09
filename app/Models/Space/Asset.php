<?php

namespace App\Models\Space;

use App\Enums\AssetRightsStatus;
use App\Jobs\Space\SignalPublishedAssetUsageJob;
use App\Models\Management\Storage;
use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use App\Services\Asset\AssetUsageService;
use App\Services\Automation\CurrentSpaceResolver;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $filename
 * @property string $extension
 * @property string $mime_type
 * @property string|null $path
 * @property string $storage_id
 * @property string|null $folder_id
 * @property int $size
 * @property string|null $checksum
 * @property array<array-key, mixed>|null $metadata
 * @property array<array-key, mixed>|null $data
 * @property array<array-key, mixed>|null $tags
 * @property \Illuminate\Support\Carbon|null $license_expires_at
 * @property string $rights_status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Space\AssetFolder|null $folder
 * @property-read string $full_path
 * @property-read Storage|null $storage
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AssetVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Database\Factories\Space\AssetFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereExtension($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereFolderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereStorageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereTags($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset withoutTrashed()
 * @mixin \Eloquent
 */
class Asset extends SpaceModel
{
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    /**
     * Columns `AssetHandler::replaceContentAssets()` puts into delivered content.
     */
    private const DELIVERED_ATTRIBUTES = [
        'filename',
        'extension',
        'mime_type',
        'path',
        'storage_id',
        'size',
        'metadata',
        'data',
    ];

    /** @var list<string>|null Asset ids waiting for one usage lookup, null outside a bulk write. */
    private static ?array $deferredDeliveryRefresh = null;

    protected string $spaceChannel = 'assets';

    protected $table = 'assets';

    protected $fillable = [
        'external_id',
        'filename',
        'extension',
        'mime_type',
        'path',
        'storage_id',
        'folder_id',
        'size',
        'checksum',
        'metadata',
        'data',
        'tags',
        'license_expires_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'data' => 'array',
        'tags' => 'array',
        'license_expires_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::saving(function (self $asset) {
            if ($asset->isDirty('license_expires_at')) {
                $asset->rights_status = AssetRightsStatus::fromExpiry($asset->license_expires_at)->value;
            }
        });

        // Any asset change can alter what a public share shows (smart rules,
        // selections, folders) — one memoized ping burst per request.
        static::saved(fn () => \App\Services\Asset\AssetSharePinger::pingAllAccessible());
        static::deleted(fn () => \App\Services\Asset\AssetSharePinger::pingAllAccessible());

        static::updated(function (self $asset): void {
            if ($asset->wasChanged(self::DELIVERED_ATTRIBUTES)) {
                $asset->refreshDeliveryCache();
            }
        });
        static::deleted(fn (self $asset) => $asset->refreshDeliveryCache());
        static::restored(fn (self $asset) => $asset->refreshDeliveryCache());
    }

    /**
     * Delivery overlays live asset data (url, file details, alt texts, focus,
     * dimensions) onto content, but its CDN cache is keyed on the space's
     * `content_updated_at`. Bump it when published content shows this asset,
     * or edits stay invisible until the next unrelated publish. The same
     * change is signalled to `content_published` automations per entry, so
     * frontends that cache pages can drop the ones showing the asset.
     */
    protected function refreshDeliveryCache(): void
    {
        if (self::$deferredDeliveryRefresh !== null) {
            self::$deferredDeliveryRefresh[] = $this->id;

            return;
        }

        self::refreshDeliveryCacheFor([$this->id]);
    }

    /**
     * Runs a bulk write with one usage lookup at the end instead of one per
     * asset, each of which scans the space's content versions. Wrap this around
     * the transaction, so the bump follows the commit. Never hold it across a
     * yield: the state is static.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function batchingDeliveryRefresh(callable $callback): mixed
    {
        self::$deferredDeliveryRefresh = [];

        try {
            return $callback();
        } finally {
            $assetIds = self::$deferredDeliveryRefresh;
            self::$deferredDeliveryRefresh = null;

            self::refreshDeliveryCacheFor($assetIds);
        }
    }

    /** @param  list<string>  $assetIds */
    private static function refreshDeliveryCacheFor(array $assetIds): void
    {
        if ($assetIds === []) {
            return;
        }

        $space = app(CurrentSpaceResolver::class)->resolve();

        if ($space && app(AssetUsageService::class)->anyPublished($assetIds)) {
            $space->touchContentUpdatedAt();
            SignalPublishedAssetUsageJob::dispatchFor($space, $assetIds);
        }
    }

    /**
     * Metadata keys only the server writes. Their paths name storage keys, so
     * client input must never set them.
     */
    public const SERVER_METADATA_KEYS = ['thumbnails', 'generated_thumbnails'];

    /**
     * The filename is part of storage keys and package entry paths, so it
     * never keeps a path separator, whichever writer sets it.
     */
    protected function filename(): Attribute
    {
        return Attribute::set(function (?string $value): ?string {
            $clean = $this->purifyValue($value, 'removeAll');

            return $clean === null ? null : str_replace(['/', '\\'], '-', $clean);
        });
    }

    /**
     * Whether `$path` is a storage key inside this asset's own directory.
     * Stored paths and metadata are not trusted to name keys: on a shared
     * bucket a foreign key reads or deletes another space's files.
     */
    public function ownsStoragePath(string $spaceId, mixed $path): bool
    {
        if (! is_string($path) || str_contains($path, '\\')) {
            return false;
        }

        if (array_intersect(explode('/', $path), ['..', '.']) !== []) {
            return false;
        }

        return str_starts_with($path, "{$spaceId}/{$this->id}/");
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class, 'storage_id', 'id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AssetFolder::class, 'folder_id', 'id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class, 'asset_id', 'id')->orderByDesc('version_number');
    }

    public function getFullPathAttribute(): string
    {
        return $this->storage_id . '/' . $this->path;
    }

    public function getUrl(): ?string
    {
        return app(\App\Services\Storage\AssetService::class)->getAssetUrl($this);
    }
}
