<?php

namespace App\Models\Space;

use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server-built zip package of asset files, stored on the transfers disk.
 * Space-database model: packages belong to the space whose assets they zip;
 * the hourly download-bandwidth rollup lives in the management database.
 *
 * @property string $id
 * @property string|null $name
 * @property string $source_type 'collection'|'selection'|'folder'
 * @property string|null $collection_id
 * @property string|null $folder_id
 * @property array<int, string>|null $asset_ids
 * @property string $state 'pending'|'building'|'completed'|'failed'
 * @property int $progress
 * @property string|null $error
 * @property string|null $s3_path
 * @property int|null $file_size
 * @property string|null $checksum
 * @property int $asset_count
 * @property bool $is_stale
 * @property string|null $created_by_id Management-database users id
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 *
 * @mixin \Eloquent
 */
class AssetPackage extends SpaceModel
{
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasUlids;

    public const string STATE_PENDING = 'pending';

    public const string STATE_BUILDING = 'building';

    public const string STATE_COMPLETED = 'completed';

    public const string STATE_FAILED = 'failed';

    public const string SOURCE_COLLECTION = 'collection';

    public const string SOURCE_SELECTION = 'selection';

    public const string SOURCE_FOLDER = 'folder';

    protected $table = 'asset_packages';

    protected string $spaceChannel = 'assets';

    protected $fillable = [
        'name',
        'source_type',
        'collection_id',
        'folder_id',
        'asset_ids',
        'state',
        'progress',
        'error',
        's3_path',
        'file_size',
        'checksum',
        'asset_count',
        'is_stale',
        'created_by_id',
        'expires_at',
    ];

    protected $casts = [
        'asset_ids' => 'array',
        'progress' => 'integer',
        'file_size' => 'integer',
        'asset_count' => 'integer',
        'is_stale' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id', 'id');
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isBuilding(): bool
    {
        return $this->state === self::STATE_BUILDING;
    }

    public function isCompleted(): bool
    {
        return $this->state === self::STATE_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A package that can be handed out for download right now.
     */
    public function isDownloadable(): bool
    {
        return $this->isCompleted()
            && ! $this->is_stale
            && ! $this->isExpired()
            && ! empty($this->s3_path);
    }

    public function markAsBuilding(): void
    {
        $this->update([
            'state' => self::STATE_BUILDING,
            'progress' => 0,
            'error' => null,
        ]);
    }

    public function markAsCompleted(string $s3Path, int $fileSize, string $checksum, int $assetCount, \DateTimeInterface $expiresAt): void
    {
        // `is_stale` is deliberately left untouched: the source may have been
        // flagged stale while this build was running (the build resolves its
        // asset list up-front), in which case the finished zip must still be
        // considered stale and trigger a rebuild on the next download.
        $this->update([
            'state' => self::STATE_COMPLETED,
            's3_path' => $s3Path,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'asset_count' => $assetCount,
            'progress' => 100,
            'error' => null,
            'expires_at' => $expiresAt,
        ]);

        $this->refresh();
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'state' => self::STATE_FAILED,
            'error' => mb_substr($error, 0, 255),
        ]);
    }

    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(99, max(0, $progress))]);
    }

    /**
     * Un-metered presigned S3 URL (internal/management downloads).
     */
    public function getDownloadUrl(int $minutes = 15): string
    {
        return app(\App\Services\Asset\ShareDeliveryService::class)
            ->transferDownloadUrl($this->s3_path, now()->addMinutes($minutes));
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
